<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Api;

use AOP\Advice\Api\ThrowsAdviceInterface;

final class DuplicateThrows implements ThrowsAdviceInterface
{
    public function afterThrowingOne(\LogicException $e): void
    {
    }

    public function afterThrowingTwo(\LogicException $e): void
    {
    }
}
