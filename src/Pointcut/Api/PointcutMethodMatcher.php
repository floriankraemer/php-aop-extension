<?php

declare(strict_types=1);

namespace AOP\Pointcut\Api;

/**
 * The MethodMatcher view of an AbstractMethodMatcherPointcut.
 *
 * @internal
 */
final readonly class PointcutMethodMatcher implements MethodMatcherInterface
{
    public function __construct(private AbstractMethodMatcherPointcut $pointcut)
    {
    }

    public function matches(\ReflectionFunctionAbstract $function, ?\ReflectionClass $class): bool
    {
        return $this->pointcut->matches($function, $class);
    }

    public function isRuntime(): bool
    {
        return $this->pointcut->isRuntime();
    }

    public function matchesRuntime(\ReflectionFunctionAbstract $function, ?\ReflectionClass $class, array $args): bool
    {
        return $this->pointcut->matchesArguments($function, $class, $args);
    }
}
