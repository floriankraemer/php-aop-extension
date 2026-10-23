<?php

declare(strict_types=1);

namespace AOP\JoinPoint;

/**
 * The signature of an advice method, for `advice-execution` join points.
 */
final readonly class AdviceSignature extends MethodSignature
{
    public static function fromReflection(\ReflectionMethod $method): self
    {
        return new self($method->getDeclaringClass()->getName(), $method->getName(), $method);
    }

    public function toShortString(): string
    {
        return sprintf('adviceexecution(%s)', parent::toShortString());
    }

    public function toLongString(): string
    {
        return sprintf('adviceexecution(%s)', parent::toLongString());
    }
}
