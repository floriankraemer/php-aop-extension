<?php

declare(strict_types=1);

namespace AOP\JoinPoint;

final readonly class MethodSignature
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

    /**
     * Example: UserService::findUser(..).
     */
    public function toShortString(): string
    {
        $position = strrpos($this->className, '\\');
        $shortName = false === $position ? $this->className : substr($this->className, $position + 1);

        return sprintf('%s::%s(..)', $shortName, $this->methodName);
    }

    /**
     * Example: App\Service\UserService::findUser(int, ...string).
     */
    public function toLongString(): string
    {
        $parameters = array_map(
            static function (\ReflectionParameter $parameter): string {
                $type = $parameter->getType();
                $typeName = null === $type ? 'mixed' : (string) $type;

                return ($parameter->isVariadic() ? '...' : '').$typeName;
            },
            $this->method->getParameters(),
        );

        return sprintf('%s::%s(%s)', $this->className, $this->methodName, implode(', ', $parameters));
    }
}
