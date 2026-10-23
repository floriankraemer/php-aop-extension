--TEST--
Woven (the default): the opcache JIT stays on and advice fires on JIT-compiled code
--EXTENSIONS--
aop
--SKIPIF--
<?php
if (!extension_loaded('Zend OPcache')) die('skip opcache not loaded');
if (ini_get('opcache.jit') === false) die('skip opcache built without JIT');
if (ini_get('aop.interception') !== 'woven') die('skip needs the default aop.interception=woven');
if (getenv('USE_ZEND_ALLOC') === '0') die('skip the JIT compiler is not valgrind-clean (uninitialised reads in ir_sparse_set)');
?>
--INI--
opcache.enable=1
opcache.enable_cli=1
opcache.jit=tracing
opcache.jit_buffer_size=16M
opcache.jit_hot_func=1
opcache.jit_hot_loop=1
opcache.jit_hot_return=1
opcache.jit_hot_side_exit=1
display_startup_errors=1
--FILE--
<?php
$jit = opcache_get_status(false)['jit'];
var_dump($jit['enabled'], $jit['on']);

function hot(int $i): int { return $i; }
class Svc { public function work(int $i): int { return $i * 2; } }

function loop(int $n): int
{
    $svc = new Svc();
    $sum = 0;
    for ($i = 0; $i < $n; $i++) {
        $sum += hot($i) + $svc->work($i);
    }
    return $sum;
}

$calls = 0;
\AOP\Engine::register(
    static fn (array $jp): int => in_array($jp['name'], ['hot', 'work'], true) ? \AOP\Engine::ADVISE : 0,
    static function (array $jp, ?object $self, array $args, \AOP\OriginalCall $original) use (&$calls): mixed {
        $calls++;
        return $original($args) + 1;
    }
);
var_dump(loop(2000), $calls);

\AOP\Engine::unregister();
$calls = 0;
var_dump(loop(2000), $calls);

\AOP\Engine::register(
    static fn (array $jp): int => 'work' === $jp['name'] ? \AOP\Engine::ADVISE : 0,
    static function (array $jp, ?object $self, array $args, \AOP\OriginalCall $original) use (&$calls): mixed {
        $calls++;
        return $original($args);
    }
);
var_dump(loop(2000), $calls);

ob_start();
phpinfo(INFO_MODULES);
$info = ob_get_clean();
var_dump(str_contains($info, 'Interception backend => woven'));
var_dump(str_contains($info, 'opcache JIT => compatible (aop.interception=woven)'));
var_dump(str_contains($info, 'Field interception => not available (aop.interception=woven)'));
?>
--EXPECT--
bool(true)
bool(true)
int(6001000)
int(4000)
int(5997000)
int(0)
int(5997000)
int(2000)
bool(true)
bool(true)
bool(true)
