--TEST--
The aop extension is loaded
--EXTENSIONS--
aop
--INI--
opcache.jit=disable
--FILE--
<?php
var_dump(extension_loaded('aop'));
?>
--EXPECT--
bool(true)
