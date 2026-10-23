--TEST--
Proceed zero, one and two times, with changed arguments, and after the dispatcher returned
--EXTENSIONS--
aop
--FILE--
<?php
require __DIR__.'/000-helper.inc';

class Counter
{
    public int $calls = 0;
    public function add(int $a, int $b = 10) { ++$this->calls; return $a + $b; }
}

$mode = null;
$saved = null;
advise(['Counter::add'], function (array $jp, ?object $self, array $args, \AOP\OriginalCall $original) use (&$mode, &$saved) {
    switch ($mode) {
        case 'zero': return -1;
        case 'one': return $original($args);
        case 'two': return [$original($args), $original([100, 200])];
        case 'changed': return $original([$args[0] * 2, 5]);
        case 'named': return $original(['b' => 1, 'a' => 2]);
        case 'save': $saved = $original; return 'saved';
    }
});

$c = new Counter();
foreach (['zero', 'one', 'two', 'changed', 'named', 'save'] as $mode) {
    echo $mode, ': ', json_encode($c->add(3)), ' calls=', $c->calls, "\n";
}
$mode = 'one';
unset($c);
echo "after dispatch: ", $saved([7, 8]), ' ', $saved([1]), ' ', $saved->__invoke([2, 2]), "\n";
var_dump(is_callable($saved));
$closure = Closure::fromCallable($saved);
echo $closure([5, 5]), "\n";
echo $saved->__invoke(['a' => 1, 'b' => 1]), "\n";
try {
    $saved(['a' => 1, 2]);
} catch (\Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    $saved([]);
} catch (\ArgumentCountError $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    $saved(['nope' => 1]);
} catch (\Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
$saved = null;
echo "done\n";
?>
--EXPECT--
zero: -1 calls=0
one: 13 calls=1
two: [13,300] calls=3
changed: 11 calls=4
named: 3 calls=5
save: "saved" calls=5
after dispatch: 15 11 4
bool(true)
10
2
Error: Cannot use positional argument after named argument
ArgumentCountError: Too few arguments to function Counter::add(), 0 passed and at least 1 expected
Error: Unknown named parameter $nope
done
