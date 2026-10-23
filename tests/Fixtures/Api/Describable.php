<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Api;

interface Describable
{
    public function describe(string $prefix = ''): string;

    public function self(): static;
}
