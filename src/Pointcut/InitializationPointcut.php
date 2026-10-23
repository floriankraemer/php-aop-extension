<?php

declare(strict_types=1);

namespace AOP\Pointcut;

use AOP\JoinPoint\JoinPointKind;
use AOP\Pointcut\Pattern\MethodPattern;

/**
 * The initialization of an object: kind `initialization` (a class without constructor) and the outermost
 * `constructor-execution` (decided at runtime through RuntimeMatchContext::isOutermost(); a `parent::__construct()` call
 * is not an initialization of its own). The pattern is a constructor pattern: `App\Foo::__construct(..)`, or `App\Foo+`
 * for any constructor of the type and its subtypes.
 */
final class InitializationPointcut implements PointcutInterface, FieldInterceptionAwareInterface
{
    public function __construct(private readonly MethodPattern $pattern)
    {
    }

    public function matchesClass(?\ReflectionClass $class, StaticMatchContext $ctx): bool
    {
        return self::appliesTo($ctx->kind) && $this->pattern->matchesClass($class, $ctx->introducedInterfaces);
    }

    public function matchesMethod(?\ReflectionFunctionAbstract $method, StaticMatchContext $ctx): bool
    {
        if (!self::appliesTo($ctx->kind)) {
            return false;
        }
        if (null === $method) {
            return JoinPointKind::INITIALIZATION === $ctx->kind
                && null !== $ctx->targetClass
                && $this->pattern->matchesImplicitConstructor($ctx->targetClass, $ctx->introducedInterfaces);
        }

        return $method instanceof \ReflectionMethod && $this->pattern->matches($method, $ctx->targetClass, $ctx->introducedInterfaces);
    }

    private static function appliesTo(JoinPointKind $kind): bool
    {
        return JoinPointKind::INITIALIZATION === $kind || JoinPointKind::CONSTRUCTOR_EXECUTION === $kind;
    }

    public function isRuntime(): bool
    {
        return true; // a constructor-execution counts only when it is the outermost one
    }

    public function matchesRuntime(RuntimeMatchContext $ctx): bool
    {
        return JoinPointKind::CONSTRUCTOR_EXECUTION !== $ctx->staticContext->kind || $ctx->isOutermost();
    }

    public function bindings(): array
    {
        return [];
    }

    public function isCallerSensitive(): bool
    {
        return false;
    }

    /**
     * Whether a class has a constructor is unknown when the Weaver is enabled, and a constructor-less class is
     * initialized only under field interception, so every initialization pointcut counts.
     */
    public function requiresFieldInterception(): bool
    {
        return true;
    }
}
