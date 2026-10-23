<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Aspect;

use AOP\Attribute\Aspect;
use AOP\Attribute\Before;
use AOP\Attribute\DeclareParents;
use AOP\JoinPoint\JoinPointInterface;
use AOP\Tests\Fixtures\Introduction\BareTarget;
use AOP\Tests\Fixtures\Introduction\Tracked;
use AOP\Tests\Fixtures\Introduction\TrackedImpl;
use AOP\Tests\Fixtures\Log;

/**
 * Introduces `Tracked` and advises its method through `this()` of the parent interface.
 */
#[Aspect]
#[DeclareParents(BareTarget::class, Tracked::class, TrackedImpl::class)]
final class TrackedIntroductionAspect
{
    #[Before('execution(* track(..)) && this(AOP\Tests\Fixtures\Introduction\Trackable)')]
    public function before(JoinPointInterface $jp): void
    {
        Log::add('this(Trackable) '.$jp->getSignature()->methodName);
    }
}
