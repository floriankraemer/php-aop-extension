<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Api;

#[Mark]
trait MarkedTrait
{
    public function fromTrait(): int
    {
        return 5;
    }
}
