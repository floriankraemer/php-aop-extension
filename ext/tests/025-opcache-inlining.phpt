--TEST--
opcache does not inline advised functions that only return a constant (aop.disable_inlining=1, the default)
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
function constant_body() { return 'original'; }
function real_body($x) { return "original $x"; }
\AOP\Engine::register(fn (array $jp) => $jp['internal'] ? 0 : 1, fn () => 'advised');
var_dump(constant_body());  // pass 16 would inline it; aop.disable_inlining keeps the call
var_dump(real_body(1));
?>
--EXPECT--
string(7) "advised"
string(7) "advised"
