<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Api;

class Customer implements Describable
{
    public function describe(string $prefix = ''): string
    {
        return 'customer';
    }

    public function self(): static
    {
        return $this;
    }

    public function name(): string
    {
        return 'name';
    }
}
