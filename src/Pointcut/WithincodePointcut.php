<?php

declare(strict_types=1);

namespace AOP\Pointcut;

use AOP\JoinPoint\JoinPointKind;
use AOP\Pointcut\Pattern\MethodPattern;

/**
 * The code containing the join point is a method, constructor or function matching the pattern: the executing function
 * itself for execution kinds, initialization and advice execution, the caller's function (`StaticMatchContext::$caller`)
 * for calls, field accesses and handlers. False while the enclosing function is unknown.
 */
final class WithincodePointcut implements PointcutInterface
{
    public function __construct(private readonly MethodPattern $pattern)
    {
    }

    public function matchesClass(?\ReflectionClass $class, StaticMatchContext $ctx): bool
    {
        if (!JoinPointShape::isCode($ctx->kind)) {
            return true; // decided per caller in matchesMethod()
        }

        return $this->pattern->matchesClass($class, $ctx->introducedInterfaces);
    }

    public function matchesMethod(?\ReflectionFunctionAbstract $method, StaticMatchContext $ctx): bool
    {
        if (JoinPointShape::isCode($ctx->kind)) {
            if (null === $method) {
                return JoinPointKind::INITIALIZATION === $ctx->kind
                    && null !== $ctx->targetClass
                    && $this->pattern->matchesImplicitConstructor($ctx->targetClass, $ctx->introducedInterfaces);
            }

            return $this->pattern->matches($method, $ctx->targetClass, $ctx->introducedInterfaces);
        }
        $function = $ctx->caller?->function;

        return null !== $function && $this->pattern->matches($function, $ctx->caller?->class);
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
        return true;
    }
}
