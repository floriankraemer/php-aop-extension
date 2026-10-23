<?php

declare(strict_types=1);

namespace AOP\JoinPoint;

final readonly class FieldSignature implements SignatureInterface
{
    public function __construct(
        public string $className,
        public string $propertyName,
        public ?\ReflectionProperty $property = null,
    ) {
    }

    public function getName(): string
    {
        return $this->propertyName;
    }

    public function getDeclaringTypeName(): string
    {
        return $this->className;
    }

    /**
     * Example: UserService::$count.
     */
    public function toShortString(): string
    {
        return sprintf('%s::$%s', SignatureNames::shortName($this->className), $this->propertyName);
    }

    public function toLongString(): string
    {
        $type = $this->property?->getType();

        return sprintf('%s%s::$%s', null === $type ? '' : $type.' ', $this->className, $this->propertyName);
    }
}
