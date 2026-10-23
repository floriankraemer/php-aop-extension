<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Api;

final class DescribableImpl implements Describable
{
    public function describe(string $prefix = ''): string
    {
        return $prefix.'described';
    }

    public function self(): static
    {
        return $this;
    }
}
