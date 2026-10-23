<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Aspect;

use AOP\Attribute\Around;
use AOP\Attribute\Aspect;
use AOP\Attribute\Before;
use AOP\JoinPoint\JoinPointInterface;
use AOP\JoinPoint\ProceedingJoinPointInterface;
use AOP\Tests\Fixtures\Log;

/**
 * Advises the proxy target fixtures used by ProxyFactoryTest.
 */
#[Aspect]
final class TargetsAspect
{
    /** @var list<JoinPointInterface> */
    public array $joinPoints = [];

    #[Around('execution(public * AOP\Tests\Fixtures\Targets\**::*(..)) && !execution(* AOP\Tests\Fixtures\Targets\**::notAdvised(..)) && !service(plain.*)')]
    public function around(ProceedingJoinPointInterface $pjp): mixed
    {
        $this->joinPoints[] = $pjp;
        Log::add('around>'.$pjp->getSignature()->getName());
        try {
            return $pjp->proceed();
        } finally {
            Log::add('around<');
        }
    }

    #[Before('execution(* AOP\Tests\Fixtures\Targets\FinalWithInterface::*(..)) && this(AOP\Tests\Fixtures\Targets\FinalWithInterface)')]
    public function onlyForSubclassProxies(): void
    {
        Log::add('this(FinalWithInterface)');
    }
}
