<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Api;

use AOP\JoinPoint\JoinPointInterface;
use AOP\Pointcut\ControlFlowInterface;

final readonly class Flow implements ControlFlowInterface
{
    /**
     * @param list<JoinPointInterface> $stack
     */
    public function __construct(private array $stack)
    {
    }

    public function stack(): array
    {
        return $this->stack;
    }
}
