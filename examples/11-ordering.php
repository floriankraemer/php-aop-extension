<?php

declare(strict_types=1);

namespace Demo\Ordering;

require __DIR__.'/../vendor/autoload.php';

use AOP\Advisor\Ordered;
use AOP\Attribute\Around;
use AOP\Attribute\Aspect;
use AOP\Attribute\DeclarePrecedence;
use AOP\Attribute\Order;
use AOP\JoinPoint\ProceedingJoinPointInterface;
use AOP\Weaver;

final class Handler
{
    public function handle(string $request): string
    {
        return "handled $request";
    }
}

/** Runs first: `#[Order]`, lower values are outer. */
#[Aspect]
#[Order(1)]
final class SecurityAspect
{
    #[Around('execution(* Demo\Ordering\Handler::handle(..))')]
    public function wrap(ProceedingJoinPointInterface $pjp): mixed
    {
        return $this->named('security', $pjp);
    }

    private function named(string $name, ProceedingJoinPointInterface $pjp): mixed
    {
        echo "$name in\n";
        $result = $pjp->proceed();
        echo "$name out\n";

        return $result;
    }
}

/** The `Ordered` interface gives the order of an aspect object. */
#[Aspect]
final class MetricsAspect implements Ordered
{
    public function getOrder(): int
    {
        return 5;
    }

    #[Around('execution(* Demo\Ordering\Handler::handle(..))')]
    public function wrap(ProceedingJoinPointInterface $pjp): mixed
    {
        echo "metrics in\n";
        $result = $pjp->proceed();
        echo "metrics out\n";

        return $result;
    }
}

/**
 * `#[DeclarePrecedence]` says which aspect is outer. It overrides #[Order] between the aspects it names.
 * This class has no #[Order] at all, so only the declared precedence puts it first.
 */
#[Aspect]
#[DeclarePrecedence('Demo\Ordering\TracingAspect, Demo\Ordering\SecurityAspect')]
final class TracingAspect
{
    #[Around('execution(* Demo\Ordering\Handler::handle(..))')]
    public function wrap(ProceedingJoinPointInterface $pjp): mixed
    {
        echo "tracing in\n";
        $result = $pjp->proceed();
        echo "tracing out\n";

        return $result;
    }
}

echo "--- SecurityAspect (#[Order(1)]) and MetricsAspect (Ordered, 5)\n";
$weaver = Weaver::fromAspects([new MetricsAspect(), new SecurityAspect()]);
$weaver->enable();
echo (new Handler())->handle('a')."\n";
$weaver->disable();

echo "--- SecurityAspect and TracingAspect (declared first)\n";
$weaver = Weaver::fromAspects([new SecurityAspect(), new TracingAspect()]);
$weaver->enable();
echo (new Handler())->handle('b')."\n";
$weaver->disable();

/* Expected output:
--- SecurityAspect (#[Order(1)]) and MetricsAspect (Ordered, 5)
security in
metrics in
metrics out
security out
handled a
--- SecurityAspect and TracingAspect (declared first)
tracing in
security in
security out
tracing out
handled b
*/
