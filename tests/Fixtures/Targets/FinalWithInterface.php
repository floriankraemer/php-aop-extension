<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Targets;

use AOP\Tests\Fixtures\Log;

final class FinalWithInterface implements GreetingInterface, ShoutInterface, \Countable
{
    public function name(): string
    {
        Log::add('name');

        return 'final';
    }

    public function greet(string $who, string $greeting = self::DEFAULT_GREETING): string
    {
        Log::add('greet');

        return $greeting.' '.$who;
    }

    public function fluent(): static
    {
        return $this;
    }

    public function shout(string $text): string
    {
        Log::add('shout');

        return strtoupper($text);
    }

    public function count(): int
    {
        return 42;
    }

    public function notOnInterface(): string
    {
        return 'hidden';
    }
}
