--TEST--
Fields: __get/__set, dynamic properties, accesses from inside the class and from __get itself
--EXTENSIONS--
aop
--FILE--
<?php
require __DIR__.'/100-helper.inc';
class Bag
{
    private array $data = [];
    public function __get($name) { echo "  __get($name)\n"; return $this->data[$name] ?? null; }
    public function __set($name, $value) { echo "  __set($name)\n"; $this->data[$name] = $value; }
}
#[AllowDynamicProperties]
class Open {}

g_register(['field-get', 'field-set'], null, true);

$b = new Bag();
$b->colour = 'red';      // field-set colour (served by __set), then field-set data inside __set
var_dump($b->colour);    // field-get colour, __get, field-get data
$o = new Open();
$o->dyn = 1;
var_dump($o->dyn);
var_dump($o->missing ?? 'default'); // BP_VAR_IS: no warning from the original
?>
--EXPECT--
  ? field-get Bag *
  ? field-set Bag colour
  > field-set Bag::colour('red') from {main}@15
  __set(colour)
  ? field-get Bag data
  > field-get Bag::data() from Bag::__set@7
  < []
  ? field-get Bag colour
  > field-get Bag::colour() from {main}@16
  __get(colour)
  > field-get Bag::data() from Bag::__get@6
  < ['red']
  < 'red'
string(3) "red"
  ? field-get Open *
  ? field-set Open dyn
  > field-set Open::dyn(1) from {main}@18
  ? field-get Open dyn
  > field-get Open::dyn() from {main}@19
  < 1
int(1)
  ? field-get Open missing
  > field-get Open::missing() from {main}@20
  < NULL
string(7) "default"
