--TEST--
aop.interception: an invalid value is reported and the default (woven) stays
--EXTENSIONS--
aop
--INI--
opcache.jit=disable
aop.interception=sideways
display_startup_errors=1
--FILE--
<?php
var_dump(\AOP\Engine::fieldInterceptionAvailable());
ob_start();
phpinfo(INFO_MODULES);
var_dump(str_contains(ob_get_clean(), 'Interception backend => woven'));
?>
--EXPECTF--
%AWarning: aop.interception: invalid value "sideways", expected woven, hook or auto in Unknown on line 0
%Abool(false)
bool(true)
