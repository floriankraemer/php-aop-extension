<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Aspect;

use AOP\Attribute\Aspect;
use AOP\Attribute\Before;
use AOP\JoinPoint\JoinPointInterface;

/**
 * Observes by-reference arguments of RefTarget; overwriting the received value must not reach the caller.
 */
#[Aspect]
final class RefBeforeAspect
{
    /** @var list<array<int|string, mixed>> */
    public array $args = [];

    /** @var list<int> */
    public array $bound = [];

    #[Before('execution(* AOP\Tests\Fixtures\Targets\RefTarget::*(..))')]
    public function record(JoinPointInterface $jp): void
    {
        $args = $jp->getArgs();
        $this->args[] = $args;
        $args[0] = 'overwritten by advice';
    }

    #[Before('execution(* AOP\Tests\Fixtures\Targets\RefTarget::increment(..)) && args(n)')]
    public function bind(int $n): void
    {
        $this->bound[] = $n;
        $n = -1;
    }
}
