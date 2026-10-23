--TEST--
PHP 8.4+: property hook bodies are no method join points; advised methods using hooked properties work
--EXTENSIONS--
aop
--SKIPIF--
<?php if (PHP_VERSION_ID < 80400) die('skip PHP 8.4+ (property hooks)'); ?>
--FILE--
<?php
require __DIR__.'/000-helper.inc';

function helper($v) { return "helper($v)"; }

class User
{
    public string $name {
        get => ucfirst($this->name);
        set => $this->name = strtolower($value);
    }
    public string $label {
        get => helper($this->name);   // a call made from inside a hook
    }
    public function __construct(string $name) { $this->name = $name; }
    public function greet() { return "hi {$this->name}"; }
}
class Admin extends User
{
    public string $name {
        get => 'admin ' . parent::$name::get();
    }
}

$seen = [];
\AOP\Engine::register(
    function (array $jp) use (&$seen) {
        $seen[] = jp_name($jp);
        return in_array($jp['name'], ['greet', 'helper', '__construct'], true) ? \AOP\Engine::ADVISE : 0;
    },
    function (array $jp, ?object $self, array $args, \AOP\OriginalCall $original) {
        $c = $jp['caller'];
        echo '> ', jp_name($jp), ' from ', $c === null ? 'null' : ($c['scope'] ?? '-') . '::' . ($c['function'] ?? '-'), "\n";
        return $original($args);
    }
);

$u = new User('ALICE');
var_dump($u->name, $u->greet(), $u->label);
$u->name = 'BOB';
var_dump($u->name);
$a = new Admin('ROOT');
var_dump($a->name);
var_dump(array_values(array_filter($seen, fn ($n) => str_contains($n, '$'))));
?>
--EXPECT--
> User::__construct from -::-
> User::greet from -::-
> helper from User::$label::get
string(5) "Alice"
string(8) "hi Alice"
string(13) "helper(Alice)"
string(3) "Bob"
> Admin::__construct from -::-
string(10) "admin Root"
array(0) {
}
