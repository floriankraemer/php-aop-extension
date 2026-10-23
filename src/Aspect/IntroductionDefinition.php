<?php

declare(strict_types=1);

namespace AOP\Aspect;

final readonly class IntroductionDefinition
{
    /**
     * @param class-string $interface
     * @param class-string $defaultImpl
     */
    public function __construct(
        public string $typePattern,
        public string $interface,
        public string $defaultImpl,
    ) {
    }
}
