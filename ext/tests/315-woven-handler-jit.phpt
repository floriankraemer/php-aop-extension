--TEST--
Woven catch blocks keep the opcache JIT on and the exception-handler advice fires on JIT-compiled code
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
var_dump(opcache_get_status(false)['jit']['on']);

function risky(int $i): int
{
    try {
        if ($i % 2) {
            throw new RuntimeException('odd');
        }
        return $i;
    } catch (LogicException | RuntimeException) {
        return -1;
    }
}

$caught = 0;
\AOP\Engine::register(
    fn (array $jp) => 'exception-handler' === $jp['kind'] ? \AOP\Engine::ADVISE : 0,
    function (array $jp, ?object $self, array $args, \AOP\OriginalCall $orig) use (&$caught) {
        ++$caught;
    }
);
$sum = 0;
for ($i = 0; $i < 200; $i++) {
    $sum += risky($i);
}
var_dump($sum, $caught);
?>
--EXPECT--
bool(true)
int(9800)
int(100)
