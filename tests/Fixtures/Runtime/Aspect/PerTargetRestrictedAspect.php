<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Runtime\Aspect;

use AOP\Aspect\AspectScope;
use AOP\Attribute\Aspect;
use AOP\Attribute\Before;
use AOP\Tests\Fixtures\Log;

#[Aspect(scope: AspectScope::PER_TARGET, pointcut: 'execution(* AOP\\Tests\\Fixtures\\Runtime\\TargetService::pub(..))')]
final class PerTargetRestrictedAspect
{
    #[Before('execution(* AOP\Tests\Fixtures\Runtime\TargetService::*(..))')]
    public function note(): void
    {
        Log::add('PerTargetRestrictedAspect:'.spl_object_id($this));
    }
}
