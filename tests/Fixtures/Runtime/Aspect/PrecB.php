<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Runtime\Aspect;

use AOP\Attribute\Aspect;
use AOP\Attribute\Before;
use AOP\Attribute\DeclarePrecedence;
use AOP\Attribute\Order;
use AOP\Tests\Fixtures\Log;

#[Aspect]
#[Order(2)]
#[DeclarePrecedence('AOP\\Tests\\Fixtures\\Runtime\\Aspect\\PrecC, *, AOP\\Tests\\Fixtures\\Runtime\\Aspect\\PrecA')]
final class PrecB
{
    #[Before('execution(* AOP\Tests\Fixtures\Runtime\TargetService::pub(..))')]
    public function note(): void
    {
        Log::add('PrecB');
    }
}
