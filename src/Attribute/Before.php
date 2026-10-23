<?php

declare(strict_types=1);

namespace AOP\Attribute;

#[\Attribute(\Attribute::TARGET_METHOD)]
final class Before
{
    public function __construct(public readonly string $pointcut)
    {
    }
}
