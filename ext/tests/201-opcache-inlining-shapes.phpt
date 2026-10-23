--TEST--
aop.disable_inlining: every inlinable shape stays a call; untyped parameters behave as before (RECV opcodes run)
--EXTENSIONS--
aop
--SKIPIF--
<?php
if (!extension_loaded('Zend OPcache')) die('skip opcache not loaded');
?>
--INI--
opcache.enable_cli=1
opcache.optimization_level=0x7FFEBFFF
--FILE--
<?php
const ANSWER = 42;
function k_string() { return 'original'; }
function k_const() { return ANSWER; }         // not inlined anyway (opcache keeps global constant fetches)
function k_empty() {}                         // implicit return null; an unused call becomes a NOP
function k_args($a, $b = 2) { return 'original'; }
final class Svc
{
    const X = 'x';
    public function run() { return [$this->priv(), self::stat(), $this->fin(), static::stat()]; }
    private function priv() { return 'original'; }
    public static function stat() { return self::X; }   // class constant: inlined too
    public function fin() { return 1.5; }
}
$log = [];
\AOP\Engine::register(
    fn (array $jp) => $jp['internal'] || !str_ends_with($jp['kind'], '-execution') ? 0 : 1,
    function (array $jp, ?object $self, array $args, \AOP\OriginalCall $original) use (&$log) {
        $log[] = $jp['name'];
        return $jp['name'] === 'run' ? $original($args) : 'advised';
    }
);
var_dump(k_string(), k_const(), k_empty(), k_args(1));
k_empty();
var_dump((new Svc())->run());
echo implode(',', $log), "\n";
\AOP\Engine::unregister();

echo "-- untyped parameters\n";
function untyped($a, &$b, $c = 'C', ...$rest) { $b = 'written'; return [$a, $c, $rest]; }
$v = 'before';
var_dump(untyped(1, $v), $v);
var_dump(untyped(1, $v, 3, 4, x: 5));
try {
    untyped();
} catch (\ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
$r = new ReflectionFunction('untyped');
var_dump($r->getParameters()[0]->hasType());
?>
--EXPECTF--
string(7) "advised"
string(7) "advised"
string(7) "advised"
string(7) "advised"
array(4) {
  [0]=>
  string(7) "advised"
  [1]=>
  string(7) "advised"
  [2]=>
  string(7) "advised"
  [3]=>
  string(7) "advised"
}
k_string,k_const,k_empty,k_args,k_empty,run,priv,stat,fin,stat
-- untyped parameters
array(3) {
  [0]=>
  int(1)
  [1]=>
  string(1) "C"
  [2]=>
  array(0) {
  }
}
string(7) "written"
array(3) {
  [0]=>
  int(1)
  [1]=>
  int(3)
  [2]=>
  array(2) {
    [0]=>
    int(4)
    ["x"]=>
    int(5)
  }
}
Too few arguments to function untyped(), 0 passed in %s on line %d and at least 2 expected
bool(false)
