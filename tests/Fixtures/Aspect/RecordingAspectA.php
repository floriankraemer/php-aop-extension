<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Aspect;

use AOP\Attribute\After;
use AOP\Attribute\AfterReturning;
use AOP\Attribute\AfterThrowing;
use AOP\Attribute\Around;
use AOP\Attribute\Aspect;
use AOP\Attribute\Before;
use AOP\Attribute\Order;
use AOP\JoinPoint\JoinPointInterface;
use AOP\JoinPoint\ProceedingJoinPointInterface;
use AOP\Tests\Fixtures\Log;

#[Aspect]
#[Order(1)]
final class RecordingAspectA
{
    #[Around('execution(* AOP\Tests\Fixtures\Service\**::*(..))')]
    public function around(ProceedingJoinPointInterface $pjp): mixed
    {
        Log::add('A.around>');
        try {
            return $pjp->proceed();
        } finally {
            Log::add('A.around<');
        }
    }

    #[Before('execution(* AOP\Tests\Fixtures\Service\**::*(..))')]
    public function before(JoinPointInterface $jp): void
    {
        Log::add('A.before');
    }

    #[After('execution(* AOP\Tests\Fixtures\Service\**::*(..))')]
    public function after(JoinPointInterface $jp): void
    {
        Log::add('A.after');
    }

    #[AfterReturning('execution(* AOP\Tests\Fixtures\Service\**::*(..))', returning: 'result')]
    public function afterReturning(mixed $result): void
    {
        Log::add('A.afterReturning');
    }

    #[AfterThrowing('execution(* AOP\Tests\Fixtures\Service\**::*(..))', throwing: 'e')]
    public function afterThrowing(\Throwable $e): void
    {
        Log::add('A.afterThrowing');
    }
}
