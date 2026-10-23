<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Api;

final class SavingsAccount extends Account
{
    public function getBalance(): int
    {
        return 200;
    }

    public function interest(): int
    {
        return 5;
    }
}
