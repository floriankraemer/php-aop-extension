<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Aspect;

use AOP\Attribute\AfterReturning;
use AOP\Attribute\Aspect;
use AOP\Attribute\Before;
use AOP\Attribute\DeclareParents;
use AOP\Attribute\Order;
use AOP\JoinPoint\JoinPointInterface;
use AOP\Tests\Fixtures\Introduction\Auditable;
use AOP\Tests\Fixtures\Introduction\AuditTrail;
use AOP\Tests\Fixtures\Log;

/**
 * Spring's usage tracking example: every `*WorkTarget` becomes Auditable, and each `work()` call records itself
 * on the introduced implementation through `this()`.
 */
#[Aspect]
#[Order(3)]
#[DeclareParents('AOP\Tests\Fixtures\Introduction\*WorkTarget', Auditable::class, AuditTrail::class)]
final class IntroductionAspect
{
    #[AfterReturning('execution(* AOP\Tests\Fixtures\Introduction\*WorkTarget::work(..)) && this(auditable)')]
    public function record(JoinPointInterface $jp, Auditable $auditable): void
    {
        $args = $jp->getArgs();
        $auditable->recordAudit($jp->getSignature()->getName().'('.(\is_string($args[0] ?? null) ? $args[0] : '').')');
    }

    #[Before('execution(* AOP\Tests\Fixtures\Introduction\Auditable::*(..))')]
    public function introduced(JoinPointInterface $jp): void
    {
        Log::add('introduced '.$jp->getSignature()->getName());
    }

    #[Before('target(AOP\Tests\Fixtures\Introduction\Auditable) || within(AOP\Tests\Fixtures\Introduction\Auditable+)')]
    public function targetOrWithin(JoinPointInterface $jp): void
    {
        Log::add('target or within '.$jp->getSignature()->getName());
    }
}
