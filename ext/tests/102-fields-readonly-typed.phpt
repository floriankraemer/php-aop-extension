--TEST--
Fields: readonly and typed properties (initialization, modification errors, coercion, TypeError, typed references)
--EXTENSIONS--
aop
--FILE--
<?php
require __DIR__.'/100-helper.inc';
final class Money
{
    public readonly int $amount;
    public int $count = 0;
    public ?string $label;
    public array $list = [];
    public function __construct(int $amount) { $this->amount = $amount; }
    public function __clone() { $this->amount = $this->amount * 2; } // readonly re-initialization in __clone (8.3)
}
g_register(['field-get', 'field-set']);

$m = new Money(5);
echo "modify readonly: ";
try {
    $m->amount = 6; // no join point: the write fails anyway
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo "readonly by reference: ";
try {
    $r = &$m->amount;
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
$c = clone $m;
var_dump($c->amount);

echo "coercion:\n";
$m->count = '7';
var_dump($m->count);
echo "type error:\n";
try {
    $m->count = 'seven';
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
echo "uninitialized:\n";
try {
    $m->label;
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
echo "typed reference:\n";
$ref = &$m->count;
try {
    $ref = 'not an int';
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
var_dump($m->count);
echo "auto-vivification into a typed int:\n";
try {
    $m->count[] = 1;
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
$m->list[] = 'a';
var_dump($m->list);
?>
--EXPECTF--
  > field-set Money::amount(5) from Money::__construct@9
modify readonly: Error: Cannot modify readonly property Money::$amount
readonly by reference: Error: Cannot %Smodify readonly property Money::$amount
  > field-get Money::amount() from Money::__clone@10
  < 5
  > field-set Money::amount(10) from Money::__clone@10
  > field-get Money::amount() from {main}@28
  < 10
int(10)
coercion:
  > field-set Money::count('7') from {main}@31
  > field-get Money::count() from {main}@32
  < 7
int(7)
type error:
  > field-set Money::count('seven') from {main}@35
Cannot assign string to property Money::$count of type int
uninitialized:
  > field-get Money::label() from {main}@41
Typed property Money::$label must not be accessed before initialization
typed reference:
  > field-get Money::count() from {main}@46
  < 7
Cannot assign string to reference held by property Money::$count of type int
  > field-get Money::count() from {main}@52
  < 7
int(7)
auto-vivification into a typed int:
  > field-get Money::count() from {main}@55
  < 7
Cannot use a scalar value as an array
  > field-get Money::list() from {main}@59
  < []
  > field-get Money::list() from {main}@60
  < ['a']
array(1) {
  [0]=>
  string(1) "a"
}
