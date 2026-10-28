--TEST--
PHP 8.5+: calls made by the pipe operator are join points; #[\NoDiscard] is checked once, at the call site
--EXTENSIONS--
aop
--SKIPIF--
<?php if (PHP_VERSION_ID < 80500) die('skip PHP 8.5+ (pipe operator, #[\NoDiscard])'); ?>
--INI--
opcache.jit=disable
--FILE--
<?php
require __DIR__.'/000-helper.inc';

function double($x) { return $x * 2; }
class M { public static function inc($x) { return $x + 1; } }
#[\NoDiscard]
function important() { return 'important'; }

advise(['double', 'M::inc', 'strrev', 'important']);
var_dump(21 |> double(...) |> M::inc(...) |> strval(...) |> strrev(...));
var_dump(5 |> (fn ($x) => double($x)));
important();
?>
--EXPECTF--
> double(21)
< double = 42
> M::inc(42)
< M::inc = 43
> strrev('43')
< strrev = '34'
string(2) "34"
> double(5)
< double = 10
int(10)

Warning: The return value of function important() should either be used or intentionally ignored by casting it as (void) in %s on line %d
> important()
< important = 'important'
