<?php

declare(strict_types=1);

namespace AOP\Pointcut\Api;

use AOP\Pointcut\PointcutInterface;
use AOP\Pointcut\RuntimeMatchContext;
use AOP\Pointcut\StaticMatchContext;

/**
 * Spring's Pointcut.TRUE: matches every join point, of every kind.
 * Not a field pointcut for Weaver::enable(): under woven it just does not see field join points.
 */
enum TruePointcut implements PointcutInterface
{
    case INSTANCE;

    public function matchesClass(?\ReflectionClass $class, StaticMatchContext $ctx): bool
    {
        return true;
    }

    public function matchesMethod(?\ReflectionFunctionAbstract $function, StaticMatchContext $ctx): bool
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
