<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Runtime\Aspect;

use AOP\Attribute\Aspect;
use AOP\Attribute\DeclareError;

#[Aspect]
#[DeclareError('execution(* AOP\Tests\Fixtures\Runtime\TargetService::fail(..))', 'fail() is forbidden')]
final class DeclareErrorAspect
{
}
