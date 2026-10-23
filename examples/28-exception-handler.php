<?php

declare(strict_types=1);

namespace Demo\Handlers;

require __DIR__.'/../vendor/autoload.php';

use AOP\Attribute\Aspect;
use AOP\Attribute\Before;
use AOP\JoinPoint\JoinPointInterface;
use AOP\Weaver;

final class PaymentException extends \RuntimeException
{
}

final class Payments
{
    public function charge(int $amount): string
    {
        try {
            if ($amount > 100) {
                throw new PaymentException("limit exceeded: $amount");
            }

            return "charged $amount";
        } catch (PaymentException $e) {
            return 'declined: '.$e->getMessage();
        }
    }
}

#[Aspect]
final class CatchLog
{
    // handler(T) matches a catch clause of T. args(e) binds the caught exception to the parameter $e.
    #[Before('handler(Demo\Handlers\PaymentException) && args(e)')]
    public function log(\Throwable $e, JoinPointInterface $jp): void
    {
        echo '  caught '.$e::class.' in '.$jp->getSignature()->getDeclaringTypeName()."\n";
    }
}

#[Aspect]
final class CatchGuard
{
    // The advice runs before the catch body. Throwing here replaces the caught exception.
    #[Before('handler(Demo\Handlers\PaymentException)')]
    public function refuse(): void
    {
        throw new \LogicException('the handler refused to continue');
    }
}

// Phase 1: the advice only observes. Its return value is ignored, and the catch body runs as usual.
$logging = Weaver::fromAspects([new CatchLog()]);
$logging->enable();
$payments = new Payments();
echo $payments->charge(40)."\n";
echo $payments->charge(500)."\n";
$logging->disable();

// Phase 2: an exception thrown by the advice replaces the caught one. It is not chained as previous.
$guarding = Weaver::fromAspects([new CatchGuard()]);
$guarding->enable();
try {
    echo $payments->charge(500)."\n";
} catch (\LogicException $e) {
    echo 'outer: '.$e->getMessage().', previous: '.var_export($e->getPrevious(), true)."\n";
}

/* Expected output:
charged 40
  caught Demo\Handlers\PaymentException in Demo\Handlers\Payments
declined: limit exceeded: 500
outer: the handler refused to continue, previous: NULL
*/
