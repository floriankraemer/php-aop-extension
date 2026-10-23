<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Introduction;

readonly class ReadonlyWorkTarget implements WorkInterface
{
    public function __construct(public string $prefix)
    {
    }

    public function work(string $input): string
    {
        return $this->prefix.$input;
    }
}
