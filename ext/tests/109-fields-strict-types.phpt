--TEST--
Fields: an advised set keeps the typing mode of the accessing file; internal callers (Reflection) are coercive and have caller null
--EXTENSIONS--
aop
--FILE--
<?php
declare(strict_types=1);
require __DIR__.'/100-helper.inc';
class Typed { public int $n = 0; public float $f = 0.0; }
g_register(['field-get', 'field-set']);
$t = new Typed();
try {
    $t->n = '5';
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
$t->f = 2; // int to float is allowed in strict mode
var_dump($t->f);
(new ReflectionProperty(Typed::class, 'n'))->setValue($t, '7'); // ReflectionProperty::setValue() is coercive
var_dump($t->n);
?>
--EXPECT--
  > field-set Typed::n('5') from {main}@8
Cannot assign string to property Typed::$n of type int
  > field-set Typed::f(2) from {main}@12
  > field-get Typed::f() from {main}@13
  < 2.0
float(2)
  > field-set Typed::n('7') from null
  > field-get Typed::n() from {main}@15
  < 7
int(7)
