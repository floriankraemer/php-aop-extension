<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Aspect;

use AOP\Attribute\Aspect;
use AOP\Attribute\DeclareParents;
use AOP\Tests\Fixtures\Introduction\Auditable;
use AOP\Tests\Fixtures\Introduction\WorkTarget;

#[Aspect]
#[DeclareParents('**', Auditable::class, WorkTarget::class)]
final class IntroducesUnimplementedAspect
{
}
