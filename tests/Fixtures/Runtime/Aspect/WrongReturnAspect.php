<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Runtime\Aspect;

use AOP\Attribute\Around;
use AOP\Attribute\Aspect;
use AOP\JoinPoint\ProceedingJoinPointInterface;

#[Aspect]
final class WrongReturnAspect
{
    #[Around('execution(* AOP\Tests\Fixtures\Runtime\TargetService::count(..))')]
    public function around(ProceedingJoinPointInterface $pjp): mixed
    {
        return 'not an int';
    }
}
