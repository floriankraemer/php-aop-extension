<?php

declare(strict_types=1);

namespace AOP\Pointcut;

interface PointcutInterface
{
    /**
     * Cheap prefilter: false only when no join point on this class can match.
     *
     * @param \ReflectionClass<object>|null $class the runtime class; null for functions
     */
    public function matchesClass(?\ReflectionClass $class, StaticMatchContext $ctx): bool;

    /**
     * The full static decision. $function is null for fields and exception handlers.
     * A designator that cannot apply to the join point's kind returns false.
     */
    public function matchesMethod(?\ReflectionFunctionAbstract $function, StaticMatchContext $ctx): bool;

    /**
     * True when matchesRuntime() must be consulted for each invocation.
     */
    public function isRuntime(): bool;

    public function matchesRuntime(RuntimeMatchContext $ctx): bool;

    /**
     * @return array<string, BindingSpec> binding name => spec
     */
    public function bindings(): array;

    /**
     * True when the static result depends on `$ctx->caller` (within, this and @within on call-like kinds, ...).
     */
    public function isCallerSensitive(): bool;
}
