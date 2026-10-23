<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Runtime\Aspect;

use AOP\Advisor\Ordered;
use AOP\Attribute\Aspect;
use AOP\Attribute\Before;
use AOP\Attribute\Order;
use AOP\Tests\Fixtures\Log;

#[Aspect]
#[Order(100)]
final class OrderedByInterfaceAspect implements Ordered
{
    public function __construct(private readonly int $order)
    {
    }

    public function getOrder(): int
    {
        return $this->order;
    }

    #[Before('execution(* AOP\Tests\Fixtures\Runtime\TargetService::pub(..))')]
    public function note(): void
    {
        Log::add('ordered:'.$this->order);
    }
}
