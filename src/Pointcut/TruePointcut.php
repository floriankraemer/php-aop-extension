<?php

declare(strict_types=1);

namespace AOP\Pointcut;

/**
 * Matches every join point. It is not a field pointcut: under woven it is accepted and just does not see field
 * join points (see docs/limitations.md), and so are within(), this() and target().
 */
final class TruePointcut implements PointcutInterface
{
    public function matchesClass(?\ReflectionClass $class, StaticMatchContext $ctx): bool
    {
        return true;
    }

    public function matchesMethod(?\ReflectionFunctionAbstract $method, StaticMatchContext $ctx): bool
    {
        return true;
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

    public function isCallerSensitive(): bool
    {
        return false;
    }
}
