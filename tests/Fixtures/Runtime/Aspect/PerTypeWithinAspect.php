<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Runtime\Aspect;

use AOP\Aspect\AspectScope;
use AOP\Attribute\Aspect;
use AOP\Attribute\Before;
use AOP\Tests\Fixtures\Log;

#[Aspect(scope: AspectScope::PER_TYPE_WITHIN, pointcut: 'AOP\\Tests\\Fixtures\\Runtime\\*')]
final class PerTypeWithinAspect
{
    #[Before('execution(* AOP\Tests\Fixtures\Runtime\*::*(..))')]
    public function note(): void
    {
        Log::add('PerTypeWithinAspect:'.spl_object_id($this));
    }
}
