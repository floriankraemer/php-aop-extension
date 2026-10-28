--TEST--
Woven: the advised frame stays on the stack, behind the intercept() frame, while the dispatcher runs; dispatch inside fibers
--EXTENSIONS--
aop
--INI--
opcache.jit=disable
aop.interception=woven
--FILE--
<?php
class Svc
{
    public function work($x) { return $x; }
    public function suspendInside() { $v = Fiber::suspend('suspended'); return "resumed with $v"; }
}

\AOP\Engine::register(
    fn (array $jp) => in_array($jp['name'], ['work', 'suspendInside'], true) ? 1 : 0,
    function ($jp, $self, $args, $original) {
        if ($jp['name'] === 'work') {
            $frames = array_map(fn ($f) => ($f['class'] ?? '').($f['type'] ?? '').$f['function'], debug_backtrace());
            echo implode(' <- ', $frames), "\n";
        }
        return $original($args);
    }
);

function outer(Svc $s) { return $s->work('w'); }
outer(new Svc());

$fiber = new Fiber(function () {
    $s = new Svc();
    echo $s->work('in fiber'), "\n";
    return $s->suspendInside();
});
var_dump($fiber->start());
$fiber->resume('value');
var_dump($fiber->getReturn());
?>
--EXPECTF--
{closure%S} <- AOP\Internal\intercept <- Svc->work <- outer
{closure%S} <- AOP\Internal\intercept <- Svc->work <- {closure%S} <- Fiber->start
in fiber
string(9) "suspended"
string(18) "resumed with value"
