--TEST--
JIT: with aop.enable=0 the hooks are not installed and the opcache JIT runs
--EXTENSIONS--
aop
--SKIPIF--
<?php
if (!extension_loaded('Zend OPcache')) die('skip opcache not loaded');
if (ini_get('opcache.jit') === false) die('skip opcache built without JIT');
if (getenv('USE_ZEND_ALLOC') === '0') die('skip the JIT compiler is not valgrind-clean (uninitialised reads in ir_sparse_set)');
?>
--INI--
aop.enable=0
opcache.enable=1
opcache.enable_cli=1
opcache.jit=tracing
opcache.jit_buffer_size=16M
display_startup_errors=1
--FILE--
<?php
$jit = opcache_get_status(false)['jit'];
var_dump($jit['enabled'], $jit['on']);
function hot($n) { $s = 0; for ($i = 0; $i < $n; $i++) { $s += $i; } return $s; }
var_dump(hot(1000));
ob_start();
phpinfo(INFO_MODULES);
var_dump(str_contains(ob_get_clean(), 'opcache JIT => not affected by aop (aop.enable=0)'));
?>
--EXPECT--
bool(true)
bool(true)
int(499500)
bool(true)
