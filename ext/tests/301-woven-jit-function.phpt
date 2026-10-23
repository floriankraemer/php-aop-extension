--TEST--
Woven: advice fires under the function JIT too, including generators and constructors
--EXTENSIONS--
aop
--SKIPIF--
<?php
if (!extension_loaded('Zend OPcache')) die('skip opcache not loaded');
if (ini_get('opcache.jit') === false) die('skip opcache built without JIT');
if (getenv('USE_ZEND_ALLOC') === '0') die('skip the JIT compiler is not valgrind-clean (uninitialised reads in ir_sparse_set)');
?>
--INI--
aop.interception=woven
opcache.enable=1
opcache.enable_cli=1
opcache.jit=function
opcache.jit_buffer_size=16M
opcache.jit_hot_func=1
display_startup_errors=1
--FILE--
<?php
var_dump(opcache_get_status(false)['jit']['on']);

class Point { public function __construct(public readonly int $x) {} }
function numbers(int $n) { for ($i = 0; $i < $n; $i++) { yield $i; } return $n; }

$calls = [];
\AOP\Engine::register(
    static fn (array $jp): int => \AOP\Engine::ADVISE,
    static function (array $jp, ?object $self, array $args, \AOP\OriginalCall $original) use (&$calls): mixed {
        $calls[$jp['name']] = ($calls[$jp['name']] ?? 0) + 1;
        return $original($args);
    }
);
$sum = 0;
for ($i = 0; $i < 300; $i++) {
    $sum += (new Point($i))->x;
    foreach (numbers(3) as $n) {
        $sum += $n;
    }
}
var_dump($sum, $calls);
?>
--EXPECT--
bool(true)
int(45750)
array(2) {
  ["__construct"]=>
  int(300)
  ["numbers"]=>
  int(300)
}
