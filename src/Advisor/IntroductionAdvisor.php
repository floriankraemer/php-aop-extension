<?php

declare(strict_types=1);

namespace AOP\Advisor;

use AOP\Pointcut\Pattern\TypePattern;

/**
 * An introduction: proxies of classes matching the type pattern implement the interface through the default implementation.
 */
final readonly class IntroductionAdvisor
{
    /**
     * @param class-string $interface
     * @param class-string $defaultImpl
     */
    public function __construct(
        public string $aspectClass,
        public TypePattern $typePattern,
        public string $interface,
        public string $defaultImpl,
        public int $order,
    ) {
    }

    /**
     * A class that already implements the interface gets nothing introduced.
     *
     * @param \ReflectionClass<object> $class
     */
    public function appliesTo(\ReflectionClass $class): bool
    {
        return $this->typePattern->matchesType($class) && !$class->implementsInterface($this->interface);
    }
}
