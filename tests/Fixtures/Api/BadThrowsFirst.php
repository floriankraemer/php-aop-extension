<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Api;

use AOP\Advice\Api\ThrowsAdviceInterface;

final class BadThrowsFirst implements ThrowsAdviceInterface
{
    public function afterThrowing(string $joinPoint, \LogicException $e): void
    {
    }
}
