<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Aspect;

use AOP\Attribute\Aspect;
use AOP\Attribute\Before;
use AOP\Attribute\Order;

#[Aspect]
#[Order(1)]
final class OrderedAspect
{
    #[Before('a()')]
    public function first(): void
    {
    }

    #[Before('b()')]
    public function second(): void
    {
    }
}
