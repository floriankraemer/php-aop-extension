--TEST--
aop.enable=0 registers the classes but no hooks; register() throws
--EXTENSIONS--
aop
--INI--
aop.enable=0
--FILE--
<?php
var_dump(class_exists(\AOP\Engine::class), class_exists(\AOP\OriginalCall::class));
var_dump(ini_get('aop.enable'));
try {
    \AOP\Engine::register(fn () => 1, fn () => 'advised');
} catch (\RuntimeException $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
var_dump(\AOP\Engine::isRegistered());
function f() { return 'original'; }
var_dump(f());
ob_start();
phpinfo(INFO_MODULES);
$info = ob_get_clean();
var_dump(str_contains($info, 'Interception hooks => inactive (aop.enable=0)'));
var_dump(ini_set('aop.enable', '1'));
?>
--EXPECT--
bool(true)
bool(true)
string(1) "0"
RuntimeException: AOP\Engine::register(): interception is disabled (aop.enable=0)
bool(false)
string(8) "original"
bool(true)
bool(false)
