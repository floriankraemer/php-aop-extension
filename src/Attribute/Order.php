<?php

declare(strict_types=1);

namespace AOP\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class Order
{
    /**
     * Lower values have higher precedence (outermost).
     */
    public function __construct(public readonly int $value)
    {
    }
}
