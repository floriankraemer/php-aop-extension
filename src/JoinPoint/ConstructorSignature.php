<?php

declare(strict_types=1);

namespace AOP\JoinPoint;

/**
 * A constructor; the reflection is null for classes without one (the `initialization` join point).
 */
final readonly class ConstructorSignature implements SignatureInterface
{
    public function __construct(
        public string $className,
        public ?\ReflectionMethod $constructor = null,
    ) {
    }

    public function getName(): string
    {
        return '__construct';
    }

    public function getDeclaringTypeName(): string
    {
        return $this->className;
    }

    public function toShortString(): string
    {
        return sprintf('%s::__construct(..)', SignatureNames::shortName($this->className));
    }

    public function toLongString(): string
    {
        return sprintf('%s::__construct(%s)', $this->className, SignatureNames::parameterList($this->constructor?->getParameters() ?? []));
    }
}
