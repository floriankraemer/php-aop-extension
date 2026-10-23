<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Aspect;

use AOP\Attribute\Aspect;
use AOP\Attribute\DeclareParents;
use AOP\Tests\Fixtures\Introduction\AuditTrail;

#[Aspect]
#[DeclareParents('**', AuditTrail::class, AuditTrail::class)]
final class IntroducesClassAspect
{
}
