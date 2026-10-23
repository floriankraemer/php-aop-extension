<?php

declare(strict_types=1);

namespace Demo\Advice;

require __DIR__.'/../vendor/autoload.php';

use AOP\Attribute\After;
use AOP\Attribute\AfterReturning;
use AOP\Attribute\AfterThrowing;
use AOP\Attribute\Around;
use AOP\Attribute\Aspect;
use AOP\Attribute\Before;
use AOP\JoinPoint\JoinPointInterface;
use AOP\JoinPoint\ProceedingJoinPointInterface;
use AOP\Weaver;

final class Payments
{
    public function charge(int $amount): string
    {
        echo "  charge($amount) runs\n";

        return "charged $amount";
    }

    public function refund(int $amount): string
    {
        throw new \RuntimeException("refund of $amount rejected");
    }
}

#[Aspect]
final class PaymentAdvice
{
    #[Around('execution(* Demo\Advice\Payments::charge(..)) && args(amount)')]
    public function around(ProceedingJoinPointInterface $pjp, int $amount): mixed
    {
        echo "around: before proceed\n";
        $result = $pjp->proceed([$amount]);
        echo "around: after proceed\n";

        return $result;
    }

    #[Before('execution(* Demo\Advice\Payments::charge(..))')]
    public function before(JoinPointInterface $jp): void
    {
        echo "before: {$jp->getSignature()->getName()}\n";
    }

    #[AfterReturning(pointcut: 'execution(* Demo\Advice\Payments::charge(..))', returning: 'result')]
    public function afterReturning(string $result): void
    {
        echo "after returning: $result\n";
    }

    #[AfterThrowing(pointcut: 'execution(* Demo\Advice\Payments::refund(..))', throwing: 'e')]
    public function afterThrowing(\RuntimeException $e): void
    {
        echo "after throwing: {$e->getMessage()}\n";
    }

    #[After('execution(* Demo\Advice\Payments::*(..))')]
    public function after(): void
    {
        echo "after (runs on success and on failure)\n";
    }
}

$weaver = Weaver::fromAspects([new PaymentAdvice()]);
$weaver->enable();

$payments = new Payments();

echo "charge:\n";
echo $payments->charge(40)."\n";

echo "refund:\n";
try {
    $payments->refund(15);
} catch (\RuntimeException $e) {
    echo "caller got: {$e->getMessage()}\n";
}

/* Expected output:
charge:
around: before proceed
before: charge
  charge(40) runs
after returning: charged 40
after (runs on success and on failure)
around: after proceed
charged 40
refund:
after throwing: refund of 15 rejected
after (runs on success and on failure)
caller got: refund of 15 rejected
*/
