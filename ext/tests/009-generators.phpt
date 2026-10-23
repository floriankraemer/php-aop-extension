--TEST--
Generators: the call that creates the generator is intercepted, resumption is not
--EXTENSIONS--
aop
--FILE--
<?php
require __DIR__.'/000-helper.inc';

class Source
{
    public function numbers(int $n) { for ($i = 1; $i <= $n; ++$i) { echo "yield $i\n"; yield $i; } return 'end'; }
}
function letters() { yield 'a'; yield 'b'; }

\AOP\Engine::register(
    fn (array $jp) => in_array($jp['name'], ['numbers', 'letters'], true) ? 1 : 0,
    function (array $jp, ?object $self, array $args, \AOP\OriginalCall $original) {
        echo 'dispatch ', $jp['name'], "\n";
        $gen = $original($args);
        var_dump($gen instanceof \Generator);
        if ($jp['name'] === 'letters') {
            return (function () use ($gen) { foreach ($gen as $v) { yield strtoupper($v); } })();
        }
        return $gen;
    }
);

$g = (new Source())->numbers(3);
foreach ($g as $v) {
    echo "got $v\n";
}
var_dump($g->getReturn());
foreach (letters() as $l) {
    echo $l, "\n";
}
?>
--EXPECT--
dispatch numbers
bool(true)
yield 1
got 1
yield 2
got 2
yield 3
got 3
string(3) "end"
dispatch letters
bool(true)
A
B
