--TEST--
JIT: overriding zend_execute_ex disables the opcache JIT; PHP warns, aop adds a notice, phpinfo() says so
--EXTENSIONS--
aop
--SKIPIF--
<?php
if (!extension_loaded('Zend OPcache')) die('skip opcache not loaded');
if (ini_get('opcache.jit') === false) die('skip opcache built without JIT');
?>
--INI--
opcache.enable=1
opcache.enable_cli=1
opcache.jit=tracing
opcache.jit_buffer_size=16M
display_startup_errors=1
--FILE--
<?php
$jit = opcache_get_status(false)['jit'];
var_dump($jit['enabled'], $jit['on']);
ob_start();
phpinfo(INFO_MODULES);
$info = ob_get_clean();
var_dump(str_contains($info, 'opcache JIT => disabled: aop overrides zend_execute_ex() (set aop.enable=0 to use the JIT)'));
?>
--EXPECTF--
%ANotice: aop: the opcache JIT is disabled because aop interception overrides zend_execute_ex(); set aop.enable=0 to use the JIT in Unknown on line 0
%Abool(false)
bool(false)
bool(true)
