--TEST--
Constructors with promoted properties: advice that skips or repeats the constructor sees the same object state in both backends
--EXTENSIONS--
aop
--FILE--
<?php
class P
{
    public function __construct(public readonly int $x, public string $s = 'default', public array $a = [1], protected ?int $n = null)
    {
        echo "body {$this->x} {$this->s}\n";
    }

    public function state(): string
    {
        return json_encode([$this->x, $this->s, $this->a, $this->n]);
    }
}

class Q
{
    public function __construct(public string $s = 'default', public ?int $n = 5) {}
}

class ByRef
{
    public function __construct(public array &$list) {}
}

$mode = 'proceed';
\AOP\Engine::register(
    static fn (array $jp): int => $jp['kind'] === 'constructor-execution' && !$jp['internal'] ? \AOP\Engine::ADVISE : 0,
    static function (array $jp, ?object $self, array $args, \AOP\OriginalCall $original) use (&$mode): mixed {
        echo "> {$jp['class']} [{$mode}] ", json_encode($args), "\n";
        return 'proceed' === $mode ? $original($args) : null;
    }
);

echo "-- construct\n";
$p = new P(1, 'first');
echo $p->state(), "\n";

echo "-- skipped second call leaves the first values\n";
$q = new Q('kept', 7);
$mode = 'skip';
$p->__construct(2, 'second');
echo $p->state(), "\n";
$q->__construct('ignored');
echo json_encode([$q->s, $q->n]), "\n";

echo "-- skipped first call leaves the typed properties uninitialized\n";
$u = new P(3);
try {
    echo $u->x;
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
$q2 = new Q('x');
var_dump(isset($q2->s));

echo "-- repeated call on a readonly property fails inside the original, not before the advice\n";
$mode = 'proceed';
try {
    $p->__construct(4, 'third');
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo $p->state(), "\n";

echo "-- by-reference promotion\n";
$list = [1];
$r = new ByRef($list);
$list[] = 2;
echo json_encode($r->list), "\n";
?>
--EXPECT--
-- construct
> P [proceed] [1,"first"]
body 1 first
[1,"first",[1],null]
-- skipped second call leaves the first values
> Q [proceed] ["kept",7]
> P [skip] [2,"second"]
[1,"first",[1],null]
> Q [skip] ["ignored"]
["kept",7]
-- skipped first call leaves the typed properties uninitialized
> P [skip] [3]
Error: Typed property P::$x must not be accessed before initialization
> Q [skip] ["x"]
bool(false)
-- repeated call on a readonly property fails inside the original, not before the advice
> P [proceed] [4,"third"]
Error: Cannot modify readonly property P::$x
[1,"first",[1],null]
-- by-reference promotion
> ByRef [proceed] [[1]]
[1,2]
