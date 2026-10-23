<?php

declare(strict_types=1);

namespace AOP\Runtime;

use AOP\JoinPoint\JoinPointInterface;
use AOP\Pointcut\ControlFlowInterface;

/**
 * The stack of active join points, one per Fiber (a Fiber starts with an empty stack, like a thread):
 * what `cflow()` reads, plus the tokens of percflow aspects.
 */
final class ControlFlowStack implements ControlFlowInterface
{
    private readonly FlowState $main;

    /** @var \WeakMap<object, FlowState> */
    private readonly \WeakMap $fibers;

    public function __construct()
    {
        $this->main = new FlowState();
        $this->fibers = new \WeakMap();
    }

    public function stack(): array
    {
        return $this->state()->frames;
    }

    public function state(): FlowState
    {
        $fiber = \Fiber::getCurrent();
        if (null === $fiber) {
            return $this->main;
        }

        return $this->fibers[$fiber] ??= new FlowState();
    }

    /**
     * @param bool $counts false for advice-execution frames, which do not deepen the flow for percflowbelow
     */
    public function enter(JoinPointInterface $joinPoint, bool $counts = true): void
    {
        $state = $this->state();
        $state->frames[] = $joinPoint;
        if ($counts) {
            ++$state->depth;
        }
    }

    public function leave(bool $counts = true): void
    {
        $state = $this->state();
        array_pop($state->frames);
        if ($counts) {
            --$state->depth;
        }
    }

    /**
     * @param class-string $aspectClass
     */
    public function pushToken(string $aspectClass): void
    {
        $state = $this->state();
        $state->tokens[$aspectClass][] = new FlowToken($state->depth);
    }

    /**
     * @param class-string $aspectClass
     */
    public function popToken(string $aspectClass): void
    {
        $state = $this->state();
        if (isset($state->tokens[$aspectClass])) {
            array_pop($state->tokens[$aspectClass]);
        }
    }

    /**
     * The token of the innermost control flow the aspect is bound in; for percflowbelow only while some join point
     * below the entry is running.
     *
     * @param class-string $aspectClass
     */
    public function activeToken(string $aspectClass, bool $below): ?FlowToken
    {
        $state = $this->state();
        $tokens = $state->tokens[$aspectClass] ?? [];
        if ([] === $tokens) {
            return null;
        }
        if (!$below) {
            return $tokens[\count($tokens) - 1];
        }
        for ($i = \count($tokens) - 1; $i >= 0; --$i) {
            if ($state->depth > $tokens[$i]->depth) {
                return $tokens[$i];
            }
        }

        return null;
    }
}
