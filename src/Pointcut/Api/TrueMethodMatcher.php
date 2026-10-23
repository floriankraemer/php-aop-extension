<?php

declare(strict_types=1);

namespace AOP\Pointcut\Api;

/**
 * Spring's MethodMatcher.TRUE.
 */
enum TrueMethodMatcher implements MethodMatcherInterface
{
    case INSTANCE;

    public function matches(\ReflectionFunctionAbstract $function, ?\ReflectionClass $class): bool
    {
        return true;
    }

    public function isRuntime(): bool
    {
        return false;
    }

    public function matchesRuntime(\ReflectionFunctionAbstract $function, ?\ReflectionClass $class, array $args): bool
    {
        return true;
    }
}
