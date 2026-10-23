<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Functions;

final class ConstructedService
{
    public function __construct(public readonly string $name = 'x')
    {
    }
}
