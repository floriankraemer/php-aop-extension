--TEST--
aop.interception=auto: the woven backend when the opcache JIT is configured
--EXTENSIONS--
aop
--SKIPIF--
<?php
if (!extension_loaded('Zend OPcache')) die('skip opcache not loaded');
if (ini_get('opcache.jit') === false) die('skip opcache built without JIT');
if (getenv('USE_ZEND_ALLOC') === '0') die('skip the JIT compiler is not valgrind-clean (uninitialised reads in ir_sparse_set)');
?>
--INI--
aop.interception=auto
opcache.enable=1
opcache.enable_cli=1
opcache.jit=tracing
opcache.jit_buffer_size=16M
opcache.jit_hot_func=1
display_startup_errors=1
--FILE--
<?php
var_dump(opcache_get_status(false)['jit']['on']);
var_dump(\AOP\Engine::fieldInterceptionAvailable());
function f(int $x): int { return $x; }
\AOP\Engine::register(
    static fn (array $jp): int => 'f' === $jp['name'] ? \AOP\Engine::ADVISE : 0,
    static fn (array $jp, ?object $self, array $args, \AOP\OriginalCall $original): mixed => $original($args) + 100,
);
var_dump(f(1));
ob_start();
phpinfo(INFO_MODULES);
$info = ob_get_clean();
var_dump(str_contains($info, 'Interception backend => woven (auto: opcache JIT configured)'));
?>
--EXPECT--
bool(true)
bool(false)
int(101)
bool(true)
