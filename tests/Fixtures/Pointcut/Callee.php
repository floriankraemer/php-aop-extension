<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Pointcut;

use AOP\Tests\Fixtures\Hierarchy\Marker;

/**
 * The thing being called, read and written in the new-designator tests.
 */
#[Marker('callee')]
class Callee
{
    public int $count = 0;
    public ?string $label = null;
    protected string $secret = '';
    private static int $total = 0;
    public readonly int $fixed;
    public $untyped; // @phpstan-ignore missingType.property

    public function __construct(int $fixed = 1)
    {
        $this->fixed = $fixed;
    }

    public function find(int $id): string
    {
        return (string) $id;
    }

    public static function make(): static
    {
        return new static(); // @phpstan-ignore new.static
    }

    public static function total(): int
    {
        return self::$total;
    }
}
