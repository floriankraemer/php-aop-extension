<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Runtime\Aspect;

use AOP\Attribute\Aspect;
use AOP\Attribute\Before;
use AOP\Attribute\Order;
use AOP\Tests\Fixtures\Log;

#[Aspect]
#[Order(3)]
final class PrecC
{
    #[Before('execution(* AOP\Tests\Fixtures\Runtime\TargetService::pub(..))')]
    public function note(): void
    {
        Log::add('PrecC');
    }
}
