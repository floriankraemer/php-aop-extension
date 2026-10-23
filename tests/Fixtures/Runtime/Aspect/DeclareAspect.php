<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Runtime\Aspect;

use AOP\Attribute\Aspect;
use AOP\Attribute\DeclareWarning;

#[Aspect]
#[DeclareWarning('execution(* AOP\Tests\Fixtures\Runtime\TargetService::sealed(..))', 'do not use sealed()')]
final class DeclareAspect
{
}
