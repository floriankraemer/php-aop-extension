--TEST--
Constructors promoting a property with a set hook (8.4+): the hook runs once when advice proceeds and never when it skips
--EXTENSIONS--
aop
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80400) die('skip property hooks need PHP 8.4');
?>
--FILE--
<?php
class H
{
    public function __construct(public string $name {
        set {
            echo "set hook({$value})\n";
            $this->name = strtoupper($value);
        }
    }) {
        echo "body\n";
    }
}

$mode = 'proceed';
\AOP\Engine::register(
    static fn (array $jp): int => $jp['kind'] === 'constructor-execution' && !$jp['internal'] ? \AOP\Engine::ADVISE : 0,
    static function (array $jp, ?object $self, array $args, \AOP\OriginalCall $original) use (&$mode): mixed {
        echo "> [{$mode}] ", json_encode($args), "\n";
        return 'proceed' === $mode ? $original($args) : null;
    }
);

echo "-- proceed\n";
$h = new H('first');
echo $h->name, "\n";

echo "-- skip: no hook, value kept\n";
$mode = 'skip';
$h->__construct('second');
echo $h->name, "\n";

echo "-- skip on a new object: no hook, still uninitialized\n";
$u = new H('third');
try {
    echo $u->name;
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

echo "-- proceed again: one hook call\n";
$mode = 'proceed';
$h->__construct('fourth');
echo $h->name, "\n";
?>
--EXPECT--
-- proceed
> [proceed] ["first"]
set hook(first)
body
FIRST
-- skip: no hook, value kept
> [skip] ["second"]
FIRST
-- skip on a new object: no hook, still uninitialized
> [skip] ["third"]
Error: Typed property H::$name must not be accessed before initialization
-- proceed again: one hook call
> [proceed] ["fourth"]
set hook(fourth)
body
FOURTH
