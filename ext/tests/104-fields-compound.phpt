--TEST--
Fields: compound assignments and increments are a get and a set; write fetches (arrays, references, nested) are get-only
--EXTENSIONS--
aop
--FILE--
<?php
require __DIR__.'/100-helper.inc';
class Counter
{
    public int $n = 1;
    public array $items = [];
    public ?Counter $child = null;
    public $plain = 'a';
    public function bump() { return ++$this->n; }
}
function addOne(array &$list) { $list[] = 1; }

$c = new Counter();
g_register(['field-get', 'field-set']); // after the first object: $c is not covered
$c->n++;
echo "uncovered: $c->n\n";

$c = new Counter();
echo "+= / .= / ++ / --:\n";
$c->n += 5;
$c->plain .= 'b';
var_dump($c->bump());
$c->n--;
var_dump($c->n);
echo "??= on a set property (only the read):\n";
$c->plain ??= 'x';
echo "array append:\n";
$c->items[] = 'x';
echo "by-reference argument:\n";
addOne($c->items);
echo "reference:\n";
$r = &$c->items;
$r[] = 'via ref';
echo "foreach by reference:\n";
foreach ($c->items as &$item) {
    $item = strtoupper((string) $item);
}
unset($item);
echo "nested:\n";
$c->child = new Counter();
$c->child->n = 42;
var_dump($c->child->n);
var_dump(count($c->items), $c->items[2]);
?>
--EXPECT--
uncovered: 2
+= / .= / ++ / --:
  > field-get Counter::n() from {main}@20
  < 1
  > field-set Counter::n(6) from {main}@20
  > field-get Counter::plain() from {main}@21
  < 'a'
  > field-set Counter::plain('ab') from {main}@21
  > field-get Counter::n() from Counter::bump@9
  < 6
  > field-set Counter::n(7) from Counter::bump@9
int(7)
  > field-get Counter::n() from {main}@23
  < 7
  > field-set Counter::n(6) from {main}@23
  > field-get Counter::n() from {main}@24
  < 6
int(6)
??= on a set property (only the read):
  > field-get Counter::plain() from {main}@26
  < 'ab'
array append:
  > field-get Counter::items() from {main}@28
  < []
by-reference argument:
  > field-get Counter::items() from {main}@30
  < ['x']
reference:
  > field-get Counter::items() from {main}@32
  < ['x', 1]
foreach by reference:
  > field-get Counter::items() from {main}@35
  < ['x', 1, 'via ref']
nested:
  > field-set Counter::child(Counter) from {main}@40
  > field-get Counter::child() from {main}@41
  < Counter
  > field-set Counter::n(42) from {main}@41
  > field-get Counter::child() from {main}@42
  < Counter
  > field-get Counter::n() from {main}@42
  < 42
int(42)
  > field-get Counter::items() from {main}@43
  < ['X', '1', 'VIA REF']
  > field-get Counter::items() from {main}@43
  < ['X', '1', 'VIA REF']
int(3)
string(7) "VIA REF"
