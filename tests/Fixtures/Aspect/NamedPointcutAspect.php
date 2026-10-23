<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Aspect;

use AOP\Attribute\Aspect;
use AOP\Attribute\Before;
use AOP\Attribute\Order;
use AOP\Attribute\Pointcut;
use AOP\JoinPoint\JoinPointInterface;
use AOP\Tests\Fixtures\Log;

#[Aspect]
#[Order(5)]
final class NamedPointcutAspect
{
    #[Pointcut('execution(* **::greet(..))')]
    public function greets(): void
    {
    }

    #[Before('greets()')]
    public function viaLocalReference(JoinPointInterface $jp): void
    {
        Log::add('N.local');
    }

    #[Before('execution(* **::greet(..)) && args(name)')]
    public function withName(string $name): void
    {
        Log::add('N.name='.$name);
    }
}
