--TEST--
PHP 8.5+ fields: clone() with properties writes through the handlers: field-set join points on the wrapped clone
--EXTENSIONS--
aop
--SKIPIF--
<?php if (PHP_VERSION_ID < 80500) die('skip PHP 8.5+ (clone with)'); ?>
--FILE--
<?php
require __DIR__.'/100-helper.inc';

final class Money
{
    public function __construct(public readonly int $amount, public string $currency = 'EUR') {}
    public function withAmount(int $amount): static { return clone($this, ['amount' => $amount]); }
}
g_register(['field-get', 'field-set']);

$m = new Money(5);
$n = $m->withAmount(7);
var_dump($n->amount);
$o = clone($n, ['currency' => 'USD']);
var_dump($o->currency);
echo "readonly from outside the class: no join point\n";
try {
    clone($n, ['amount' => 9]);
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
  > field-set Money::amount(5) from Money::__construct@6
  > field-set Money::currency('EUR') from Money::__construct@6
  > field-set Money::amount(7) from null
  > field-get Money::amount() from {main}@13
  < 7
int(7)
  > field-set Money::currency('USD') from null
  > field-get Money::currency() from {main}@15
  < 'USD'
string(3) "USD"
readonly from outside the class: no join point
Error: Cannot modify protected(set) readonly property Money::$amount from global scope
