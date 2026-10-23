<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Api;

#[Mark]
class MarkedAccount
{
    public function plain(): int
    {
        return 1;
    }

    #[Mark]
    public function both(): int
    {
        return 2;
    }
}
