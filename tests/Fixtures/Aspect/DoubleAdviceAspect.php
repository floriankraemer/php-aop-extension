<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Aspect;

use AOP\Attribute\After;
use AOP\Attribute\Aspect;
use AOP\Attribute\Before;

#[Aspect]
final class DoubleAdviceAspect
{
    #[Before('a()')]
    #[After('a()')]
    public function both(): void
    {
    }
}
