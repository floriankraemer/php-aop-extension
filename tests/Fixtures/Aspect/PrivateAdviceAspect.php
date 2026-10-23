<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Aspect;

use AOP\Attribute\Aspect;
use AOP\Attribute\Before;

#[Aspect]
final class PrivateAdviceAspect
{
    /** @phpstan-ignore method.unused */
    #[Before('a()')]
    private function hidden(): void
    {
    }
}
