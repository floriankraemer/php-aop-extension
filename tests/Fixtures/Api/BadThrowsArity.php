<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Api;

use AOP\Advice\Api\ThrowsAdviceInterface;

final class BadThrowsArity implements ThrowsAdviceInterface
{
    public function afterThrowing(): void
    {
    }
}
