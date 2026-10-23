<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Runtime\Aspect;

use AOP\Aspect\AspectScope;
use AOP\Attribute\Aspect;
use AOP\Attribute\Before;
use AOP\Tests\Fixtures\Log;

#[Aspect(scope: AspectScope::PER_TARGET)]
final class PerTargetAspect
{
    #[Before('execution(* AOP\Tests\Fixtures\Runtime\TargetService::*(..))')]
    public function note(): void
    {
        Log::add('PerTargetAspect:'.spl_object_id($this));
    }
}
