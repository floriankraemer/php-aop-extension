<?php

declare(strict_types=1);

namespace AOP\Pointcut;

use AOP\JoinPoint\JoinPointKind;
use AOP\Pointcut\Pattern\MethodPattern;

/**
 * The call site of a method, constructor or function matching the pattern (`method-call`, `constructor-call`,
 * `function-call`). The pattern describes the callee exactly like execution() does. Not caller-sensitive by itself;
 * combine it with within()/withincode()/this() to restrict the caller.
 */
final class CallPointcut implements PointcutInterface
{
    public function __construct(private readonly MethodPattern $pattern)
    {
    }

    public function matchesClass(?\ReflectionClass $class, StaticMatchContext $ctx): bool
    {
        return $ctx->kind->isCall() && $this->pattern->matchesClass($class, $ctx->introducedInterfaces);
    }

    public function matchesMethod(?\ReflectionFunctionAbstract $method, StaticMatchContext $ctx): bool
    {
        if (null === $method || !$ctx->kind->isCall()) {
            return false;
        }
        if ($method instanceof \ReflectionMethod === (JoinPointKind::FUNCTION_CALL === $ctx->kind)) {
            return false;
        }

        return $this->pattern->matches($method, $ctx->targetClass, $ctx->introducedInterfaces);
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
