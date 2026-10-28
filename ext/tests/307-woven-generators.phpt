--TEST--
Woven: generator advice runs at the first resume, proceeding does not recurse, getReturn() and send() work
--EXTENSIONS--
aop
--INI--
opcache.jit=disable
aop.interception=woven
--FILE--
<?php
function gen(int $n) {
    $got = yield 'a';
    echo "got {$got}\n";
    yield 'b';
    return "r{$n}";
}

class Svc
{
    public function items(): iterable { yield 1; yield 2; }
}

$mode = 'proceed';
\AOP\Engine::register(
    static fn (array $jp): int => ($jp['internal'] || 'exception-handler' === $jp['kind']) ? 0 : \AOP\Engine::ADVISE,
    static function (array $jp, ?object $self, array $args, \AOP\OriginalCall $original) use (&$mode): mixed {
        echo '> ', $jp['name'], ' [', $mode, "]\n";
        switch ($mode) {
            case 'proceed':
                return $original($args);
            case 'array':
                return ['x' => 1, 'y' => 2];
            case 'twice':
                $first = $original($args);
                $second = $original($args);
                return (static function () use ($first, $second) {
                    yield from $first;
                    yield from $second;
                    return 'both';
                })();
            case 'throw':
                throw new LogicException('from advice');
            default:
                return 'not iterable';
        }
    }
);

echo "-- the call itself is no join point yet\n";
$g = gen(2);
echo "created\n";
foreach ($g as $k => $v) {
    echo "{$k} => {$v}\n";
}
var_dump($g->getReturn());

echo "-- send() reaches the original through yield from\n";
$g = gen(5);
echo $g->current(), "\n";
echo $g->send('S'), "\n";
$g->next();
var_dump($g->getReturn());

echo "-- methods\n";
var_dump(iterator_to_array((new Svc())->items()));

echo "-- never started: no advice\n";
$g = gen(1);
unset($g);

echo "-- advice returns an array\n";
$mode = 'array';
foreach (gen(1) as $k => $v) {
    echo "{$k} => {$v}\n";
}

echo "-- advice proceeds twice\n";
$mode = 'twice';
$g = gen(1);
foreach ($g as $k => $v) {
    echo "{$k} => {$v}\n";
}
var_dump($g->getReturn());

echo "-- advice throws\n";
$mode = 'throw';
try { foreach (gen(1) as $v); } catch (LogicException $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }

echo "-- advice returns something that cannot be iterated\n";
$mode = 'scalar';
try { foreach (gen(1) as $v); } catch (TypeError $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
?>
--EXPECT--
-- the call itself is no join point yet
created
> gen [proceed]
0 => a
got 
1 => b
string(2) "r2"
-- send() reaches the original through yield from
> gen [proceed]
a
got S
b
string(2) "r5"
-- methods
> items [proceed]
array(2) {
  [0]=>
  int(1)
  [1]=>
  int(2)
}
-- never started: no advice
-- advice returns an array
> gen [array]
x => 1
y => 2
-- advice proceeds twice
> gen [twice]
0 => a
got 
1 => b
0 => a
got 
1 => b
string(4) "both"
-- advice throws
> gen [throw]
LogicException: from advice
-- advice returns something that cannot be iterated
> gen [scalar]
TypeError: gen(): the advice of a generator function must return an array or a Traversable, string returned
