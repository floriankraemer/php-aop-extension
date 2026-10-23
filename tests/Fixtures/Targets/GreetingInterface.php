<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Targets;

interface GreetingInterface extends NamedInterface
{
    public const DEFAULT_GREETING = 'Hello';

    public function greet(string $who, string $greeting = self::DEFAULT_GREETING): string;

    public function fluent(): static;
}
