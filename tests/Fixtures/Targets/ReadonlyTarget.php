<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Targets;

readonly class ReadonlyTarget
{
    public function __construct(public string $value)
    {
    }

    public function value(): string
    {
        return $this->value;
    }
}
