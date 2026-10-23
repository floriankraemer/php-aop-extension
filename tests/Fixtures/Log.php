<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures;

final class Log
{
    /** @var list<string> */
    public static array $entries = [];

    public static function add(string $entry): void
    {
        self::$entries[] = $entry;
    }

    public static function reset(): void
    {
        self::$entries = [];
    }
}
