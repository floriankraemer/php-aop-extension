<?php

declare(strict_types=1);

namespace AOP\Pointcut\Api;

/**
 * Checks whether a method or function is advised (Spring's MethodMatcher).
 *
 * `matches()` is the static decision, taken once per (class, method). When `isRuntime()` is true,
 * `matchesRuntime()` is consulted on every call with the actual arguments.
 */
interface MethodMatcherInterface
{
    /**
     * @param \ReflectionClass<object>|null $class the runtime class; null for plain functions
     */
    public function matches(\ReflectionFunctionAbstract $function, ?\ReflectionClass $class): bool;

    public function isRuntime(): bool;

    /**
     * @param \ReflectionClass<object>|null $class
     * @param array<int|string, mixed>      $args
     */
    public function matchesRuntime(\ReflectionFunctionAbstract $function, ?\ReflectionClass $class, array $args): bool;
}
