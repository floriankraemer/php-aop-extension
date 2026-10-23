<?php

declare(strict_types=1);

namespace Demo\Functions;

require __DIR__.'/../vendor/autoload.php';

use AOP\Attribute\Around;
use AOP\Attribute\Aspect;
use AOP\Attribute\Before;
use AOP\JoinPoint\ProceedingJoinPointInterface;
use AOP\Weaver;

function formatPrice(int $cents): string
{
    return number_format($cents / 100, 2).' EUR';
}

#[Aspect]
final class FunctionAspect
{
    #[Around('execution(* Demo\Functions\formatPrice(..)) && args(cents)')]
    public function inCurrencyUnits(ProceedingJoinPointInterface $pjp, int $cents): mixed
    {
        echo "formatPrice($cents) intercepted\n";

        return $pjp->proceed([$cents * 100]);
    }

    #[Before('execution(* str_repeat(..))')]
    public function onRepeat(): void
    {
        echo "str_repeat() called\n";
    }
}

$weaver = Weaver::fromAspects([new FunctionAspect()]);
$weaver->enable();

echo formatPrice(250)."\n";
echo str_repeat('ab', 2)."\n";

/* Expected output:
formatPrice(250) intercepted
250.00 EUR
str_repeat() called
abab
*/
