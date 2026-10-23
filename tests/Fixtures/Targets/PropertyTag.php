<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Targets;

#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::TARGET_FUNCTION)]
final class PropertyTag
{
    public function __construct(public readonly string $name = '')
    {
    }
}
