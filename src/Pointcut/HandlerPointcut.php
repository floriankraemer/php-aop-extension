<?php

declare(strict_types=1);

namespace AOP\Pointcut;

use AOP\JoinPoint\JoinPointKind;
use AOP\Pointcut\Pattern\TypePattern;

/**
 * A catch clause (`exception-handler`) whose caught class (`StaticMatchContext::$caughtType`) matches the pattern;
 * with `+`, a caught class that is the type or a subtype of it. `args(e)` binds the exception.
 */
final class HandlerPointcut implements PointcutInterface
{
    public function __construct(private readonly TypePattern $type)
    {
    }

    public function matchesClass(?\ReflectionClass $class, StaticMatchContext $ctx): bool
    {
        return JoinPointKind::EXCEPTION_HANDLER === $ctx->kind && (null === $ctx->caughtType || $this->type->matchesType($ctx->caughtType));
    }

    public function matchesMethod(?\ReflectionFunctionAbstract $method, StaticMatchContext $ctx): bool
    {
        return JoinPointKind::EXCEPTION_HANDLER === $ctx->kind && null !== $ctx->caughtType && $this->type->matchesType($ctx->caughtType);
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
