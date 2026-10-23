<?php

declare(strict_types=1);

namespace AOP\Pointcut;

use AOP\Pointcut\Pattern\ModifierPattern;
use AOP\Pointcut\Pattern\NamePattern;
use AOP\Pointcut\Pattern\ParameterPattern;
use AOP\Pointcut\Pattern\TypePattern;
use AOP\Proxy\MethodEligibility;

final class ExecutionPointcut implements PointcutInterface
{
    /**
     * @param TypePattern|null $returnType    null = any (including no declared return type)
     * @param TypePattern|null $declaringType null = any
     */
    public function __construct(
        private readonly ModifierPattern $modifier,
        private readonly ?TypePattern $returnType,
        private readonly ?TypePattern $declaringType,
        private readonly NamePattern $name,
        private readonly ParameterPattern $params,
    ) {
    }

    public function matchesClass(\ReflectionClass $class, StaticMatchContext $ctx): bool
    {
        if (null === $this->declaringType) {
            return true;
        }
        foreach ([...self::hierarchy($class), ...self::introduced($ctx)] as $type) {
            if ($this->declaringType->matchesType($type)) {
                return true;
            }
        }

        return false;
    }

    public function matchesMethod(\ReflectionMethod $method, StaticMatchContext $ctx): bool
    {
        return $this->modifier->matches($method)
            && $this->name->matches($method->getName())
            && $this->params->matches($method)
            && (null === $this->returnType || $this->returnType->matchesDeclared($method->getReturnType(), $method->getDeclaringClass()))
            && $this->declaringTypeMatches($method->getName(), $ctx);
    }

    public function isRuntime(): bool
    {
        return false;
    }

    public function matchesRuntime(RuntimeMatchContext $ctx): bool
    {
        return true;
    }

    public function bindings(): array
    {
        return [];
    }

    /**
     * A target method: the target class itself, or any ancestor/interface that declares it.
     * An introduced method (the target declares none of that name publicly, as in the generated proxy): the introduced
     * interface, or any ancestor of it that declares it.
     */
    private function declaringTypeMatches(string $method, StaticMatchContext $ctx): bool
    {
        if (null === $this->declaringType) {
            return true;
        }
        if ($ctx->targetClass->hasMethod($method) && !isset(MethodEligibility::introducedMethods($ctx->targetClass, $ctx->introducedInterfaces)[$method])) {
            return $this->declaredBy($ctx->targetClass, $method);
        }
        foreach (self::introduced($ctx) as $interface) {
            if ($interface->hasMethod($method) && $this->declaredBy($interface, $method)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param \ReflectionClass<object> $root
     */
    private function declaredBy(\ReflectionClass $root, string $method): bool
    {
        if (null === $this->declaringType || $this->declaringType->matchesType($root)) {
            return true;
        }
        foreach (self::hierarchy($root) as $type) {
            if ($type->hasMethod($method)
                && $type->getMethod($method)->getDeclaringClass()->getName() === $type->getName()
                && $this->declaringType->matchesType($type)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<\ReflectionClass<object>>
     */
    private static function introduced(StaticMatchContext $ctx): array
    {
        return array_map(static fn (string $interface): \ReflectionClass => new \ReflectionClass($interface), $ctx->introducedInterfaces);
    }

    /**
     * @param \ReflectionClass<object> $class
     *
     * @return list<\ReflectionClass<object>> the class, its parents and all interfaces
     */
    private static function hierarchy(\ReflectionClass $class): array
    {
        $types = [$class];
        for ($parent = $class->getParentClass(); false !== $parent; $parent = $parent->getParentClass()) {
            $types[] = $parent;
        }
        foreach ($class->getInterfaces() as $interface) {
            $types[] = $interface;
        }

        return $types;
    }
}
