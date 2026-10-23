<?php

declare(strict_types=1);

namespace Demo\Context;

require __DIR__.'/../vendor/autoload.php';

use AOP\Attribute\Around;
use AOP\Attribute\Aspect;
use AOP\JoinPoint\ProceedingJoinPointInterface;
use AOP\Runtime\AopContext;
use AOP\Weaver;

final class Cart
{
    public function __construct(public readonly string $owner)
    {
    }

    public function checkout(): string
    {
        return $this->price();
    }

    private function price(): string
    {
        // Inside the advised call, the current join point is available without passing it along.
        $jp = AopContext::currentJoinPoint();

        return 'total for '.AopContext::currentObject()?->owner.' via '.$jp?->getSignature()->toShortString();
    }
}

#[Aspect]
final class CartAspect
{
    #[Around('execution(* Demo\Context\Cart::checkout(..)) || execution(* Demo\Context\Cart::price(..))')]
    public function around(ProceedingJoinPointInterface $pjp): mixed
    {
        // The advised join point is the current one while the advice runs.
        echo 'around sees: '.(AopContext::currentJoinPoint()?->getSignature()->toShortString() ?? 'none')."\n";

        return $pjp->proceed();
    }
}

$weaver = Weaver::fromAspects([new CartAspect()]);
$weaver->enable();

echo (new Cart('ada'))->checkout()."\n";
echo 'after the call: '.(AopContext::currentJoinPoint()?->getSignature()->toShortString() ?? 'none')."\n";

/* Expected output:
around sees: Cart::checkout(..)
around sees: Cart::price(..)
total for ada via Cart::price(..)
after the call: none
*/
