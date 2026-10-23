--TEST--
Fields: what is not covered (objects not created by new after register, isset/unset, static properties, non-standard handlers, primed oplines)
--EXTENSIONS--
aop
--FILE--
<?php
require __DIR__.'/100-helper.inc';
class Plain
{
    public $v = 'v';
    public static $s = 'static';
}
class MyException extends Exception { public $extra = 'e'; }
class MyArray extends ArrayObject { public $prop = 'p'; }
function readV($o) { return $o->v; }

$early = new Plain();
readV($early); // primes the cache slot of readV()'s fetch for class Plain
g_register(['field-get', 'field-set']);

echo "covered:\n";
$covered = new Plain();
var_dump($covered->v);
echo "not covered:\n";
var_dump($early->v);
var_dump(unserialize(serialize($covered))->v);
var_dump((new ReflectionClass(Plain::class))->newInstanceWithoutConstructor()->v);
var_dump(isset($covered->v), empty($covered->v), property_exists($covered, 'v'));
unset($covered->v);
var_dump(Plain::$s);
var_dump((new MyException())->extra);
var_dump((new MyArray())->prop);
echo "known limitation: an opline primed by an uncovered object of the same class:\n";
$covered->v = 'again';
var_dump(readV($covered));
echo "a fresh opline is covered:\n";
var_dump((fn ($o) => $o->v)($covered));
?>
--EXPECT--
covered:
  > field-get Plain::v() from {main}@18
  < 'v'
string(1) "v"
not covered:
string(1) "v"
string(1) "v"
string(1) "v"
bool(true)
bool(false)
bool(true)
string(6) "static"
string(1) "e"
string(1) "p"
known limitation: an opline primed by an uncovered object of the same class:
  > field-set Plain::v('again') from {main}@29
string(5) "again"
a fresh opline is covered:
  > field-get Plain::v() from {closure}@32
  < 'again'
string(5) "again"
