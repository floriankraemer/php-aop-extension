<?php

declare(strict_types=1);

namespace AOP\Attribute;

#[\Attribute(\Attribute::TARGET_METHOD)]
final class AfterReturning
{
    public function __construct(
        public readonly string $pointcut,
        public readonly ?string $returning = null,
    ) {
    }
}
