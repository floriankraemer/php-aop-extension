<?php

declare(strict_types=1);

namespace AOP\Pointcut;

use AOP\JoinPoint\JoinPointKind;

/**
 * Where the objects and code of a join point come from, by kind. Shared by the designators.
 *
 * @internal
 */
final class JoinPointShape
{
    private function __construct()
    {
    }

    /**
     * The join point is the code itself (as opposed to a call site, a field access or a handler inside other code).
     */
    public static function isCode(JoinPointKind $kind): bool
    {
        return $kind->isExecution() || JoinPointKind::INITIALIZATION === $kind;
    }

    /**
     * The function whose code contains the join point: the executing function itself for code kinds, the caller's
     * function for everything that happens inside other code. Null when unknown (top-level code, internal callers,
     * a class without constructor).
     */
    public static function enclosingFunction(StaticMatchContext $ctx, ?\ReflectionFunctionAbstract $function): ?\ReflectionFunctionAbstract
    {
        return self::isCode($ctx->kind) ? $function : $ctx->caller?->function;
    }

    /**
     * Classes whose lexical code contains the join point: the declaring class for executions, the caller's class
     * for everything that happens inside other code. Empty when unknown or for plain functions.
     *
     * @return list<\ReflectionClass<object>>
     */
    public static function enclosingClasses(StaticMatchContext $ctx, ?\ReflectionFunctionAbstract $function): array
    {
        if (self::isCode($ctx->kind)) {
            if ($function instanceof \ReflectionMethod) {
                return [$function->getDeclaringClass()];
            }

            return JoinPointKind::FUNCTION_EXECUTION === $ctx->kind || null === $ctx->targetClass ? [] : [$ctx->targetClass];
        }
        $caller = $ctx->caller;
        if (null === $caller) {
            return [];
        }
        if ($caller->function instanceof \ReflectionMethod) {
            return [$caller->function->getDeclaringClass()];
        }

        return null === $caller->class ? [] : [$caller->class];
    }

    /**
     * The class of the executing object, null when there is none (static code, functions, unknown caller).
     * For call-like kinds this is the caller's class.
     *
     * @return \ReflectionClass<object>|null
     */
    public static function thisClass(StaticMatchContext $ctx, ?\ReflectionFunctionAbstract $function): ?\ReflectionClass
    {
        if (self::isCode($ctx->kind)) {
            return $function instanceof \ReflectionMethod && $function->isStatic() ? null : $ctx->targetClass;
        }
        $caller = $ctx->caller;
        if (null === $caller || ($caller->function instanceof \ReflectionMethod && $caller->function->isStatic())) {
            return null;
        }

        return $caller->function instanceof \ReflectionFunction ? null : $caller->class;
    }

    /**
     * The class of the object the member belongs to, null when there is none.
     *
     * @return \ReflectionClass<object>|null
     */
    public static function targetClass(StaticMatchContext $ctx, ?\ReflectionFunctionAbstract $function): ?\ReflectionClass
    {
        if (JoinPointKind::EXCEPTION_HANDLER === $ctx->kind) {
            return null;
        }
        if ($function instanceof \ReflectionMethod && $function->isStatic()) {
            return null;
        }
        if ($ctx->property?->isStatic()) {
            return null;
        }

        return $ctx->targetClass;
    }
}
