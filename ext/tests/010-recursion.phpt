--TEST--
Recursion and nested advised calls inside the dispatcher
--EXTENSIONS--
aop
--FILE--
<?php
require __DIR__.'/000-helper.inc';

function fib($n) { return $n < 2 ? $n : fib($n - 1) + fib($n - 2); }
function audit($what) { return "audit($what)"; }

$depth = 0;
$calls = 0;
\AOP\Engine::register(
    fn (array $jp) => in_array($jp['name'], ['fib', 'audit'], true) ? 1 : 0,
    function (array $jp, ?object $self, array $args, \AOP\OriginalCall $original) use (&$depth, &$calls) {
        if ($jp['name'] === 'audit') {
            return '['.$original($args).']';
        }
        ++$calls;
        ++$depth;
        $note = audit($args[0]);   // a nested advised call inside the dispatcher
        $r = $original($args);
        --$depth;
        if ($depth === 0) {
            echo "outermost: $note\n";
        }
        return $r;
    }
);

var_dump(fib(10), $calls);

echo "-- deep recursion\n";
function down($n) { return $n === 0 ? 0 : 1 + down($n - 1); }
\AOP\Engine::register(fn (array $jp) => $jp['name'] === 'down' ? 1 : 0,
    fn ($jp, $self, $args, $original) => $original($args));
var_dump(down(2000));
?>
--EXPECT--
outermost: [audit(10)]
int(55)
int(177)
-- deep recursion
int(2000)
