<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Aspect;

use AOP\Attribute\After;
use AOP\Attribute\Aspect;

#[Aspect]
final class StaticAdviceAspect
{
    #[After('a()')]
    public static function stat(): void
    {
    }
}
