--TEST--
Known limitation: opcache inlines functions that only return a constant (optimizer pass 16)
--EXTENSIONS--
aop
--SKIPIF--
<?php
if (!function_exists('opcache_get_status') || !@opcache_get_status(false)) die('skip opcache not active');
?>
--INI--
opcache.enable_cli=1
opcache.optimization_level=0x7FFEBFFF
--FILE--
<?php
function constant_body() { return 'original'; }
function real_body($x) { return "original $x"; }
\AOP\Engine::register(fn (array $jp) => $jp['internal'] ? 0 : 1, fn () => 'advised');
var_dump(constant_body());  // inlined at compile time: never reaches the engine
var_dump(real_body(1));
?>
--EXPECT--
string(8) "original"
string(7) "advised"
