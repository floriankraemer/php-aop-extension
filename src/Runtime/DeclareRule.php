<?php

declare(strict_types=1);

namespace AOP\Runtime;

use AOP\Pointcut\PointcutInterface;

/**
 * A `#[DeclareWarning]` or `#[DeclareError]` of an aspect.
 */
final readonly class DeclareRule
{
    /**
     * @param class-string $aspectClass
     */
    public function __construct(
        public string $aspectClass,
        public PointcutInterface $pointcut,
        public string $message,
        public bool $isError,
    ) {
    }
}
