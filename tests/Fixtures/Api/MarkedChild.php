<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Api;

final class MarkedChild extends MarkedAccount implements MarkedContract
{
    public function both(): int
    {
        return 3;
    }

    public function contract(): int
    {
        return 4;
    }
}
