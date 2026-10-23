<?php

declare(strict_types=1);

namespace AOP\Pointcut\Api;

/**
 * Base for pointcuts that decide once per (class, method): implement `matches()`.
 */
abstract class StaticMethodMatcherPointcut extends AbstractMethodMatcherPointcut
{
    final public function isRuntime(): bool
    {
        return false;
    }

    final public function matchesArguments(\ReflectionFunctionAbstract $function, ?\ReflectionClass $class, array $args): bool
    {
        return true;
    }
}
