--TEST--
PHP 8.4+ fields: wrapped objects reset as lazy ghosts/proxies; advice does not initialize them, proceeding does; clones
--EXTENSIONS--
aop
--SKIPIF--
<?php if (PHP_VERSION_ID < 80400) die('skip PHP 8.4+ (lazy objects)'); ?>
--INI--
opcache.jit=disable
aop.interception=hook
--FILE--
<?php
require __DIR__.'/100-helper.inc';

class Repo
{
    public array $rows = [];
    public readonly string $id;
    public function __construct() { echo "  Repo::__construct\n"; $this->rows = ['a', 'b']; $this->id = 'r1'; }
    public function __clone() { echo "  Repo::__clone\n"; $this->rows[] = 'clone'; }
}

$r = new ReflectionClass(Repo::class);
$proceed = true;
g_register(['field-get', 'field-set'], static function (array $jp, ?object $self, array $args, \AOP\OriginalCall $original) use ($r, &$proceed): mixed {
    echo '  > ', $jp['kind'], ' ', $jp['class'], '::', $jp['name'], ' #', spl_object_id($self), ' lazy=', var_export($r->isUninitializedLazyObject($self), true), "\n";
    if (!$proceed) {
        return 'advised';  // no proceed: the object stays lazy
    }
    return $original($args);
});

echo "-- ghost reset from a wrapped object\n";
$g = new Repo();
$r->resetAsLazyGhost($g, function (Repo $o) { echo "  init ghost\n"; $o->__construct(); });
var_dump($r->isUninitializedLazyObject($g));
$proceed = false;
var_dump($g->rows);
var_dump($r->isUninitializedLazyObject($g));
$proceed = true;
var_dump($g->rows);
var_dump($r->isUninitializedLazyObject($g));

echo "-- clone of an uninitialized wrapped ghost: initialized first, the clone is wrapped\n";
$g2 = new Repo();
$r->resetAsLazyGhost($g2, function (Repo $o) { echo "  init ghost 2\n"; $o->__construct(); });
$c = clone $g2;
var_dump($r->isUninitializedLazyObject($g2), $c->rows);

echo "-- proxy reset from a wrapped object\n";
$p = new Repo();
$r->resetAsLazyProxy($p, function (Repo $o) { echo "  init proxy\n"; return new Repo(); });
var_dump($p->rows);  // one join point on the proxy; the instance is reached without its handlers
var_dump($r->isUninitializedLazyObject($p));
echo "readonly through an initialized proxy: the write fails anyway, no join point\n";
try {
    $p->id = 'x';
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo "-- clone of an initialized wrapped proxy\n";
$pc = clone $p;
var_dump($pc->rows);

echo "-- lazy objects made by ReflectionClass are not created by new: not covered\n";
$n = $r->newLazyGhost(function (Repo $o) { echo "  init new ghost\n"; $o->__construct(); });
var_dump($n->rows);
?>
--EXPECT--
-- ghost reset from a wrapped object
  Repo::__construct
  > field-set Repo::rows #4 lazy=false
  > field-set Repo::id #4 lazy=false
bool(true)
  > field-get Repo::rows #4 lazy=true
string(7) "advised"
bool(true)
  > field-get Repo::rows #4 lazy=true
  init ghost
  Repo::__construct
  > field-set Repo::rows #4 lazy=false
  > field-set Repo::id #4 lazy=false
array(2) {
  [0]=>
  string(1) "a"
  [1]=>
  string(1) "b"
}
bool(false)
-- clone of an uninitialized wrapped ghost: initialized first, the clone is wrapped
  Repo::__construct
  > field-set Repo::rows #6 lazy=false
  > field-set Repo::id #6 lazy=false
  init ghost 2
  Repo::__construct
  > field-set Repo::rows #6 lazy=false
  > field-set Repo::id #6 lazy=false
  Repo::__clone
  > field-get Repo::rows #5 lazy=false
  > field-get Repo::rows #5 lazy=false
bool(false)
array(3) {
  [0]=>
  string(1) "a"
  [1]=>
  string(1) "b"
  [2]=>
  string(5) "clone"
}
-- proxy reset from a wrapped object
  Repo::__construct
  > field-set Repo::rows #7 lazy=false
  > field-set Repo::id #7 lazy=false
  > field-get Repo::rows #7 lazy=true
  init proxy
  Repo::__construct
  > field-set Repo::rows #10 lazy=false
  > field-set Repo::id #10 lazy=false
array(2) {
  [0]=>
  string(1) "a"
  [1]=>
  string(1) "b"
}
bool(false)
readonly through an initialized proxy: the write fails anyway, no join point
Error: Cannot modify protected(set) readonly property Repo::$id from global scope
-- clone of an initialized wrapped proxy
  Repo::__clone
  > field-get Repo::rows #8 lazy=false
array(3) {
  [0]=>
  string(1) "a"
  [1]=>
  string(1) "b"
  [2]=>
  string(5) "clone"
}
-- lazy objects made by ReflectionClass are not created by new: not covered
  init new ghost
  Repo::__construct
array(2) {
  [0]=>
  string(1) "a"
  [1]=>
  string(1) "b"
}
