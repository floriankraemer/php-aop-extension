<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Api;

final class NeedsArgsAspect
{
    public function __construct(public readonly string $required)
    {
    }

    public function before(): void
    {
    }
}
