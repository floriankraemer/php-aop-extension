<?php

declare(strict_types=1);

namespace AOP\Attribute;

#[\Attribute(\Attribute::TARGET_METHOD)]
final class Pointcut
{
    public function __construct(public readonly string $expression)
    {
    }
}
