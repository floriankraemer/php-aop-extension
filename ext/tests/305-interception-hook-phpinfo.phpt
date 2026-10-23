--TEST--
aop.interception=hook: phpinfo() rows and field interception
--EXTENSIONS--
aop
--INI--
aop.interception=hook
--FILE--
<?php
var_dump(\AOP\Engine::fieldInterceptionAvailable());
ob_start();
phpinfo(INFO_MODULES);
$info = ob_get_clean();
var_dump(str_contains($info, 'Interception backend => hook'));
var_dump(str_contains($info, 'aop.interception => hook => hook'));
var_dump(str_contains($info, 'Field interception => available'));
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
