<?php

declare(strict_types=1);

namespace AOP\Pointcut;

use AOP\JoinPoint\JoinPointKind;

/**
 * The execution of an advice (`advice-execution`). `StaticMatchContext::$function` is the advice method and
 * `$targetClass` the aspect class, so within(), withincode() and this() can narrow it to an aspect.
 */
final class AdviceExecutionPointcut implements PointcutInterface
{
    public function matchesClass(?\ReflectionClass $class, StaticMatchContext $ctx): bool
    {
        return JoinPointKind::ADVICE_EXECUTION === $ctx->kind;
    }

    public function matchesMethod(?\ReflectionFunctionAbstract $method, StaticMatchContext $ctx): bool
    {
        return JoinPointKind::ADVICE_EXECUTION === $ctx->kind;
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
