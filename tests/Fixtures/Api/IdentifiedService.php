<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Api;

class IdentifiedService implements Identified
{
    public function __construct(private readonly string $id = 'x')
    {
    }

    public function id(): string
    {
        return $this->id;
    }
}
