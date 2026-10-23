--TEST--
Woven code in eval(): functions, classes and catch blocks compiled by eval are intercepted
--EXTENSIONS--
aop
--FILE--
<?php
$seen = [];
\AOP\Engine::register(
    static fn (array $jp): int => (!$jp['internal'] && (str_ends_with($jp['kind'], '-execution') || 'exception-handler' === $jp['kind'])) ? \AOP\Engine::ADVISE : 0,
    static function (array $jp, ?object $self, array $args, \AOP\OriginalCall $original) use (&$seen): mixed {
        $seen[] = $jp['kind'].' '.($jp['class'] ?? '').'::'.$jp['name'];
        return 'exception-handler' === $jp['kind'] ? null : $original($args);
    }
);

$value = eval(<<<'CODE'
namespace Evald;

function twice(int $n): int { return $n * 2; }

class Box
{
    public function __construct(public int $v) {}
    public function safe(): string
    {
        try {
            throw new \RuntimeException('x');
        } catch (\LogicException | \RuntimeException $e) {
            return 'caught';
        }
    }
}

return [twice(21), (new Box(1))->safe()];
CODE);
echo json_encode($value), "\n";

echo "-- eval inside a function, nested eval\n";
function outer()
{
    return eval('return (function () { return eval("function inner(): int { return 7; } return inner();"); })();');
}
var_dump(outer());
echo implode("\n", $seen), "\n";
?>
--EXPECT--
[42,"caught"]
-- eval inside a function, nested eval
int(7)
function-execution ::Evald\twice
constructor-execution Evald\Box::__construct
method-execution Evald\Box::safe
exception-handler Evald\Box::RuntimeException
function-execution ::outer
function-execution ::inner
