<?php

declare(strict_types=1);

namespace AOP\Pointcut;

use AOP\JoinPoint\JoinPointInterface;

/**
 * The control flow as seen from a join point somewhere on the stack: the stack up to and including that join point.
 *
 * @internal
 */
final readonly class PrefixControlFlow implements ControlFlowInterface
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
