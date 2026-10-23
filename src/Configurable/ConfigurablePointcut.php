<?php

declare(strict_types=1);

namespace AOP\Configurable;

use AOP\JoinPoint\JoinPointKind;
use AOP\Pointcut\PointcutInterface;
use AOP\Pointcut\RuntimeMatchContext;
use AOP\Pointcut\StaticMatchContext;

/**
 * Constructor executions (and initializations of constructor-less classes) of classes with `#[Configurable]`.
 */
final class ConfigurablePointcut implements PointcutInterface
{
    public function matchesClass(?\ReflectionClass $class, StaticMatchContext $ctx): bool
    {
        return null !== $class
            && (JoinPointKind::CONSTRUCTOR_EXECUTION === $ctx->kind || JoinPointKind::INITIALIZATION === $ctx->kind)
            && null !== ConfigurableAspect::attributeFor($class);
    }

    public function matchesMethod(?\ReflectionFunctionAbstract $function, StaticMatchContext $ctx): bool
    {
        return $this->matchesClass($ctx->targetClass, $ctx);
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
