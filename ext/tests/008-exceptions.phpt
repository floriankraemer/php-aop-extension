--TEST--
Exceptions from the original, from the dispatcher and from the matcher
--EXTENSIONS--
aop
--INI--
aop.interception=hook
--FILE--
<?php
require __DIR__.'/000-helper.inc';

class Svc
{
    public function fail($m) { throw new \RuntimeException($m); }
    public function ok() { return 'ok'; }
    public function broken() { return 'broken matcher, original runs'; }
}

$matcherCalls = 0;
\AOP\Engine::register(
    function (array $jp) use (&$matcherCalls) {
        if ($jp['name'] === 'broken') {
            ++$matcherCalls;
            throw new \LogicException('matcher bug');
        }
        return in_array($jp['name'], ['fail', 'ok'], true) ? 1 : 0;
    },
    function (array $jp, ?object $self, array $args, \AOP\OriginalCall $original) {
        if ($jp['name'] === 'ok') {
            throw new \DomainException('from dispatcher');
        }
        try {
            return $original($args);
        } catch (\RuntimeException $e) {
            echo 'dispatcher saw: ', $e->getMessage(), "\n";
            if ($args[0] === 'recover') {
                return 'recovered';
            }
            throw $e;
        }
    }
);

$s = new Svc();
var_dump($s->fail('recover'));
try {
    $s->fail('boom');
} catch (\RuntimeException $e) {
    echo 'caller saw: ', $e->getMessage(), "\n";
    echo 'thrown in: ', $e->getTrace()[0]['class'], '::', $e->getTrace()[0]['function'], "\n";
}
try {
    $r = $s->ok();
} catch (\DomainException $e) {
    echo 'caller saw: ', $e->getMessage(), "\n";
    $frames = array_map(fn ($f) => ($f['class'] ?? '').($f['type'] ?? '').$f['function'], $e->getTrace());
    echo 'trace: ', implode(' <- ', $frames), "\n";
}
var_dump($s->broken());
var_dump($s->broken());  // not cached: asked again
var_dump($matcherCalls);

echo "-- exception unwinding through nested advised frames\n";
function level3() { throw new \Exception('deep'); }
function level2() { return level3(); }
function level1() { try { return level2(); } finally { echo "finally in level1\n"; } }
advise(['level1', 'level2', 'level3']);
try {
    level1();
} catch (\Exception $e) {
    echo 'caught ', $e->getMessage(), "\n";
}

echo "-- exceptions around internal functions\n";
\AOP\Engine::register(fn (array $jp) => in_array($jp['name'], ['str_repeat', 'intdiv'], true) ? 1 : 0,
    function ($jp, $self, $args, $original) {
        if ($jp['name'] === 'str_repeat') {
            throw new \UnexpectedValueException('no repeating');
        }
        try {
            return $original($args);
        } catch (\DivisionByZeroError $e) {
            return 'div: '.$e->getMessage();
        }
    });
try {
    $x = str_repeat('a', 2);
} catch (\UnexpectedValueException $e) {
    echo 'caught ', $e->getMessage(), "\n";
}
var_dump(intdiv(1, 0));
try {
    intdiv(PHP_INT_MIN, -1);
} catch (\ArithmeticError $e) {
    echo 'propagated ', get_class($e), "\n";
}
?>
--EXPECTF--
dispatcher saw: recover
string(9) "recovered"
dispatcher saw: boom
caller saw: boom
thrown in: Svc::fail
caller saw: from dispatcher
trace: {closure%S} <- Svc->ok

Warning: AOP\Engine: the matcher threw LogicException (matcher bug) for Svc::broken(); the call is not intercepted in %s on line %d
string(29) "broken matcher, original runs"

Warning: AOP\Engine: the matcher threw LogicException (matcher bug) for Svc::broken(); the call is not intercepted in %s on line %d
string(29) "broken matcher, original runs"
int(2)
-- exception unwinding through nested advised frames
> level1()
> level2()
> level3()
finally in level1
caught deep
-- exceptions around internal functions
caught no repeating
string(21) "div: Division by zero"
propagated ArithmeticError
