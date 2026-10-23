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
#[Order(2)]
final class RecordingAspectB
{
    #[Around('execution(* AOP\Tests\Fixtures\Service\**::*(..))')]
    public function around(ProceedingJoinPointInterface $pjp): mixed
    {
        Log::add('B.around>');
        try {
            return $pjp->proceed();
        } finally {
            Log::add('B.around<');
        }
    }

    #[Before('execution(* AOP\Tests\Fixtures\Service\**::*(..))')]
    public function before(JoinPointInterface $jp): void
    {
        Log::add('B.before');
    }

    #[After('execution(* AOP\Tests\Fixtures\Service\**::*(..))')]
    public function after(JoinPointInterface $jp): void
    {
        Log::add('B.after');
    }

    #[AfterReturning('execution(* AOP\Tests\Fixtures\Service\**::*(..))', returning: 'result')]
    public function afterReturning(mixed $result): void
    {
        Log::add('B.afterReturning');
    }

    #[AfterThrowing('execution(* AOP\Tests\Fixtures\Service\**::*(..))', throwing: 'e')]
    public function afterThrowing(\Throwable $e): void
    {
        Log::add('B.afterThrowing');
    }
}
