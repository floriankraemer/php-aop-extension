<?php

declare(strict_types=1);

namespace AOP\TargetSource;

final readonly class PoolStats
{
    public function __construct(
        public int $maxSize,
        public int $active,
        public int $idle,
        public int $created,
        public int $borrowed,
        public int $released,
        public int $exhausted,
    ) {
    }
}
