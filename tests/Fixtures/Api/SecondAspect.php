<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Api;

use AOP\JoinPoint\JoinPointInterface;
use AOP\Tests\Fixtures\Log;

final class SecondAspect
{
    public function __construct(public readonly string $name = 'second')
    {
    }

    public function before(JoinPointInterface $jp): void
    {
        Log::add($this->name.':before');
    }
}
