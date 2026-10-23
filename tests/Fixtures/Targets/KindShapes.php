<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Targets;

use AOP\Tests\Fixtures\Hierarchy\Marker;

/**
 * Members of every shape the join point kinds need: instance and static methods, instance and static fields.
 */
#[Marker('shapes')]
class KindShapes
{
    #[PropertyTag('count')]
    public int $count = 0;

    public static int $total = 0;

    public function inc(int $by): void
    {
    }

    #[PropertyTag('make')]
    public static function make(): static
    {
        return new static(); // @phpstan-ignore new.static
    }
}
