<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Aspect;

use AOP\Attribute\Aspect;
use AOP\Attribute\DeclareParents;
use AOP\Tests\Fixtures\Introduction\Auditable;
use AOP\Tests\Fixtures\Introduction\AuditTrail;

#[Aspect]
#[DeclareParents('AOP\Tests\Fixtures\Introduction\BareTarget', Auditable::class, AuditTrail::class)]
final class IntroductionOnlyAspect
{
}
