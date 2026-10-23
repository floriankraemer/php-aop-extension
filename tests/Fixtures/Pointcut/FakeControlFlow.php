<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Pointcut;

use AOP\JoinPoint\JoinPointInterface;
use AOP\Pointcut\ControlFlowInterface;

final class FakeControlFlow implements ControlFlowInterface
{
    /**
     * @param list<JoinPointInterface> $stack outermost first, the current join point last
     */
    public function __construct(private readonly array $stack)
    {
    }

    public function stack(): array
    {
        return $this->stack;
    }
}
