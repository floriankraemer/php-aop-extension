<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Api;

final class Pooled
{
    private static int $instances = 0;

    public readonly int $number;

    public int $uses = 0;

    public function __construct()
    {
        $this->number = ++self::$instances;
    }

    public function work(string $what = 'x'): string
    {
        ++$this->uses;

        return $what.'#'.$this->number;
    }

    public function again(): static
    {
        return $this;
    }

    public function boom(): never
    {
        throw new \RuntimeException('boom '.$this->number);
    }

    public static function resetCounter(): void
    {
        self::$instances = 0;
    }
}
