<?php

declare(strict_types=1);

namespace AOP\JoinPoint;

readonly class MethodSignature implements SignatureInterface
{
    public function __construct(
        public string $className,
        public string $methodName,
        public \ReflectionMethod $method,
    ) {
    }

    public static function fromReflection(\ReflectionMethod $method): self
    {
        return new self($method->getDeclaringClass()->getName(), $method->getName(), $method);
    }

    public function getName(): string
    {
        return $this->methodName;
    }

    public function getDeclaringTypeName(): ?string
    {
        return $this->className;
    }

    /**
     * Example: UserService::findUser(..).
     */
    public function toShortString(): string
    {
        return sprintf('%s::%s(..)', SignatureNames::shortName($this->className), $this->methodName);
    }

    /**
     * Example: App\Service\UserService::findUser(int, ...string).
     */
    public function toLongString(): string
    {
        return sprintf('%s::%s(%s)', $this->className, $this->methodName, SignatureNames::parameterList($this->method->getParameters()));
    }
}
