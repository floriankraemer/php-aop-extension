<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Aspect;

use AOP\Attribute\Around;
use AOP\Attribute\Aspect;
use AOP\JoinPoint\ProceedingJoinPointInterface;

#[Aspect]
final class WrongReturnAspect
{
    public mixed $value = 42;

    #[Around('execution(* AOP\Tests\Fixtures\Targets\CountingConstructor::work(..))')]
    public function replace(ProceedingJoinPointInterface $pjp): mixed
    {
        return $this->value;
    }
}
