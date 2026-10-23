--TEST--
PHP 8.4+ fields: a hooked property access is one join point around the hook; backing-value accesses inside its own hooks are none
--EXTENSIONS--
aop
--SKIPIF--
<?php if (PHP_VERSION_ID < 80400) die('skip PHP 8.4+ (property hooks)'); ?>
--INI--
aop.interception=hook
--FILE--
<?php
declare(strict_types=1);
require __DIR__.'/100-helper.inc';

class Point { public int $x = 0; }

class User
{
    public array $log = [];
    public string $name {
        get { $this->log[] = 'get'; return ucfirst($this->name); }  // $this->log: a join point; $this->name: backing value
        set { $this->log[] = 'set'; $this->name = strtolower($value); }
    }
    public string $upper { get => strtoupper($this->name); }      // virtual, read-only
    public int $count = 0 { set(int|string $value) => $this->count = (int) $value; }
    public Point $point { get => $this->point ??= new Point(); }
    public array $tags = [] { get => $this->tags; }
    private array $store = [];
    public array $items { &get => $this->store; }
    public private(set) string $id = 'u1';
    public function __construct(string $name) { $this->name = $name; }
}
class Admin extends User
{
    public string $name {
        get => 'admin ' . parent::$name::get();
    }
}
class Base { public readonly int $v; }
class Child extends Base { public function __construct() { $this->v = 1; } } // 8.4: readonly is protected(set)

g_register(['field-get', 'field-set'], static function (array $jp, ?object $self, array $args, \AOP\OriginalCall $original): mixed {
    echo '  > ', $jp['kind'], ' ', $jp['class'], '::', $jp['name'], '(', implode(', ', array_map('g_fmt', $args)), ') scope=', $jp['scope'] ?? 'null',
        ' from ', g_caller($jp['caller']), "\n";
    if ('skip' === ($args[0] ?? null)) {
        return null;  // no proceed: the set hook does not run
    }
    $r = $original($args);
    if ('field-get' === $jp['kind']) {
        echo '  < ', g_fmt($r), "\n";
    }
    return $r;
});

echo "construct (set hook inside OriginalCall):\n";
$u = new User('ALICE');
echo "get:\n";
var_dump($u->name);
echo "set skipped by the dispatcher:\n";
$u->name = 'skip';
var_dump($u->name);
echo "virtual:\n";
var_dump($u->upper);
try {
    $u->upper = 'x';     // read-only virtual property: no join point
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo "compound:\n";
$u->count += 2;
var_dump($u->count);
echo "strict_types: the set hook's (wider) parameter type is checked, not the property's:\n";
$u->count = '40';
var_dump($u->count);
try {
    $u->count = 1.5;
} catch (TypeError $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo "write fetch of an object-valued hooked property:\n";
$u->point->x = 5;
var_dump($u->point->x);
echo "write fetch of an array-valued hooked property:\n";
try {
    $u->tags[] = 'a';
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo "by-reference get hook (no join point on write fetches):\n";
$u->items[] = 'i';
var_dump(count($u->items));
echo "private(set) from outside: no join point\n";
try {
    $u->id = 'u2';
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
var_dump($u->id);
echo "parent hook:\n";
$a = new Admin('ROOT');
var_dump($a->name);
echo "readonly initialized by a child class:\n";
$c = new Child();
var_dump($c->v);
?>
--EXPECT--
construct (set hook inside OriginalCall):
  > field-set User::name('ALICE') scope=User from User::__construct@21
  > field-get User::log() scope=User from User::$name::set@12
  < []
get:
  > field-get User::name() scope=User from {main}@48
  > field-get User::log() scope=User from User::$name::get@11
  < ['set']
  < 'Alice'
string(5) "Alice"
set skipped by the dispatcher:
  > field-set User::name('skip') scope=User from {main}@50
  > field-get User::name() scope=User from {main}@51
  > field-get User::log() scope=User from User::$name::get@11
  < ['set', 'get']
  < 'Alice'
string(5) "Alice"
virtual:
  > field-get User::upper() scope=User from {main}@53
  > field-get User::name() scope=User from User::$upper::get@14
  > field-get User::log() scope=User from User::$name::get@11
  < ['set', 'get', 'get']
  < 'Alice'
  < 'ALICE'
string(5) "ALICE"
Error: Property User::$upper is read-only
compound:
  > field-get User::count() scope=User from {main}@60
  < 0
  > field-set User::count(2) scope=User from {main}@60
  > field-get User::count() scope=User from {main}@61
  < 2
int(2)
strict_types: the set hook's (wider) parameter type is checked, not the property's:
  > field-set User::count('40') scope=User from {main}@63
  > field-get User::count() scope=User from {main}@64
  < 40
int(40)
  > field-set User::count(1.5) scope=User from {main}@66
TypeError: Cannot assign float to property User::$count of type string|int
write fetch of an object-valued hooked property:
  > field-get User::point() scope=User from {main}@71
  < Point
  > field-set Point::x(5) scope=Point from {main}@71
  > field-get User::point() scope=User from {main}@72
  < Point
  > field-get Point::x() scope=Point from {main}@72
  < 5
int(5)
write fetch of an array-valued hooked property:
  > field-get User::tags() scope=User from {main}@75
  < []
Error: Indirect modification of User::$tags is not allowed
by-reference get hook (no join point on write fetches):
  > field-get User::store() scope=User from User::$items::get@19
  < []
  > field-get User::items() scope=User from {main}@81
  > field-get User::store() scope=User from User::$items::get@19
  < ['i']
  < ['i']
int(1)
private(set) from outside: no join point
Error: Cannot modify private(set) property User::$id from global scope
  > field-get User::id() scope=User from {main}@88
  < 'u1'
string(2) "u1"
parent hook:
  > field-set Admin::name('ROOT') scope=Admin from Admin::__construct@21
  > field-get Admin::log() scope=User from Admin::$name::set@12
  < []
  > field-get Admin::name() scope=Admin from {main}@91
  > field-get Admin::log() scope=User from Admin::$name::get@11
  < ['set']
  < 'admin Root'
string(10) "admin Root"
readonly initialized by a child class:
  > field-set Child::v(1) scope=Base from Child::__construct@30
  > field-get Child::v() scope=Base from {main}@94
  < 1
int(1)
