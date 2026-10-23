--TEST--
The aop extension is loaded
--EXTENSIONS--
aop
--FILE--
<?php
var_dump(extension_loaded('aop'));
?>
--EXPECT--
bool(true)
