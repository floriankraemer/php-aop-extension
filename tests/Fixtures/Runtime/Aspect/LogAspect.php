<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Runtime\Aspect;

use AOP\Attribute\After;
use AOP\Attribute\Around;
use AOP\Attribute\Aspect;
use AOP\Attribute\Before;
use AOP\JoinPoint\JoinPointInterface;
use AOP\JoinPoint\ProceedingJoinPointInterface;
use AOP\Tests\Fixtures\Log;

#[Aspect]
final class LogAspect
{
    /** @var list<JoinPointInterface> */
    public array $seen = [];

    #[Around('execution(* AOP\Tests\Fixtures\Runtime\TargetService::*(..))')]
    public function around(ProceedingJoinPointInterface $pjp): mixed
    {
        $this->seen[] = $pjp;
        Log::add('around>'.$pjp->getSignature()->getName());

        try {
            return $pjp->proceed();
        } finally {
            Log::add('around<');
        }
    }

    #[Before('execution(* AOP\Tests\Fixtures\Runtime\TargetService::*(..))')]
    public function before(JoinPointInterface $jp): void
    {
        Log::add('before');
    }

    #[After('execution(* AOP\Tests\Fixtures\Runtime\TargetService::*(..))')]
    public function after(): void
    {
        Log::add('after');
    }
}
