<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Api;

use AOP\Advice\Api\MethodBeforeAdviceInterface;
use AOP\JoinPoint\JoinPointInterface;
use AOP\Tests\Fixtures\Log;

final class BeforeOnly implements MethodBeforeAdviceInterface
{
    public function __construct(private readonly string $label = 'before')
    {
    }

    public function before(JoinPointInterface $joinPoint): void
    {
        Log::add($this->label);
    }
}
