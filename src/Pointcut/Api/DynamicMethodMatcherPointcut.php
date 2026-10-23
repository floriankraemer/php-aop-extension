<?php

declare(strict_types=1);

namespace AOP\Pointcut\Api;

/**
 * Base for pointcuts that look at the arguments of each call: implement `matchesArguments()`.
 * The static part (`matches()`) accepts everything unless overridden.
 */
abstract class DynamicMethodMatcherPointcut extends AbstractMethodMatcherPointcut
{
    public function matches(\ReflectionFunctionAbstract $function, ?\ReflectionClass $class): bool
    {
        return true;
    }

    final public function isRuntime(): bool
    {
        return true;
    }

    abstract public function matchesArguments(\ReflectionFunctionAbstract $function, ?\ReflectionClass $class, array $args): bool;
}
