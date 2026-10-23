--TEST--
aop.disable_inlining=0: opcache inlines functions that only return a constant (optimizer pass 16), so they are not intercepted
--EXTENSIONS--
aop
--SKIPIF--
<?php
if (!extension_loaded('Zend OPcache')) die('skip opcache not loaded');
?>
--INI--
aop.interception=hook
opcache.enable_cli=1
opcache.optimization_level=0x7FFEBFFF
aop.disable_inlining=0
--FILE--
<?php
function constant_body() { return 'original'; }
function real_body($x) { return "original $x"; }
\AOP\Engine::register(fn (array $jp) => $jp['internal'] ? 0 : 1, fn () => 'advised');
var_dump(constant_body());  // inlined at compile time: never reaches the engine
var_dump(real_body(1));
var_dump(ini_get('aop.disable_inlining'));
ob_start();
phpinfo(INFO_MODULES);
var_dump(str_contains(ob_get_clean(), 'opcache function inlining => allowed (aop.disable_inlining=0)'));
?>
--EXPECT--
string(8) "original"
string(7) "advised"
string(1) "0"
bool(true)
