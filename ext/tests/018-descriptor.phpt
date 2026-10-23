--TEST--
Join point descriptors given to the matcher and to the dispatcher
--EXTENSIONS--
aop
--FILE--
<?php
class Svc
{
    public function find(int $id) { return $id; }
    public static function make() { return new static(); }
}
class Caller
{
    public function call(Svc $s) { return $s->find(7); }
}

$matched = [];
$dispatched = [];
\AOP\Engine::register(
    function (array $jp) use (&$matched) {
        if (in_array($jp['name'], ['find', 'make'], true)) {
            $matched[] = $jp;
            return 1;
        }
        return 0;
    },
    function (array $jp, ?object $self, array $args, $original) use (&$dispatched) {
        $dispatched[] = [$jp, $self, $args, get_class($original)];
        return $original($args);
    }
);
$c = new Caller();
$s = new Svc();
$c->call($s);
Svc::make();
\AOP\Engine::unregister();
var_dump($matched[0], $matched[1]);
$jp = $dispatched[0][0];
$jp['caller']['file'] = basename($jp['caller']['file']);
var_dump($jp, $dispatched[0][1] === $s, $dispatched[0][2], $dispatched[0][3], $jp['caller']['object'] === $c);
$jp = $dispatched[1][0];
$jp['caller']['file'] = basename($jp['caller']['file']);
var_dump($jp, $dispatched[1][1]);
?>
--EXPECT--
array(7) {
  ["kind"]=>
  string(16) "method-execution"
  ["class"]=>
  string(3) "Svc"
  ["scope"]=>
  string(3) "Svc"
  ["name"]=>
  string(4) "find"
  ["static"]=>
  bool(false)
  ["internal"]=>
  bool(false)
  ["caller"]=>
  NULL
}
array(7) {
  ["kind"]=>
  string(16) "method-execution"
  ["class"]=>
  string(3) "Svc"
  ["scope"]=>
  string(3) "Svc"
  ["name"]=>
  string(4) "make"
  ["static"]=>
  bool(true)
  ["internal"]=>
  bool(false)
  ["caller"]=>
  NULL
}
array(7) {
  ["kind"]=>
  string(16) "method-execution"
  ["class"]=>
  string(3) "Svc"
  ["scope"]=>
  string(3) "Svc"
  ["name"]=>
  string(4) "find"
  ["static"]=>
  bool(false)
  ["internal"]=>
  bool(false)
  ["caller"]=>
  array(6) {
    ["class"]=>
    string(6) "Caller"
    ["scope"]=>
    string(6) "Caller"
    ["function"]=>
    string(4) "call"
    ["file"]=>
    string(18) "018-descriptor.php"
    ["line"]=>
    int(9)
    ["object"]=>
    object(Caller)#3 (0) {
    }
  }
}
bool(true)
array(1) {
  [0]=>
  int(7)
}
string(16) "AOP\OriginalCall"
bool(true)
array(7) {
  ["kind"]=>
  string(16) "method-execution"
  ["class"]=>
  string(3) "Svc"
  ["scope"]=>
  string(3) "Svc"
  ["name"]=>
  string(4) "make"
  ["static"]=>
  bool(true)
  ["internal"]=>
  bool(false)
  ["caller"]=>
  array(6) {
    ["class"]=>
    NULL
    ["scope"]=>
    NULL
    ["function"]=>
    NULL
    ["file"]=>
    string(18) "018-descriptor.php"
    ["line"]=>
    int(30)
    ["object"]=>
    NULL
  }
}
NULL
