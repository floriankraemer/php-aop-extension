<?php

declare(strict_types=1);

namespace AOP\Pointcut;

final class EmptyControlFlow implements ControlFlowInterface
{
    public function stack(): array
    {
        return [];
    }
}
