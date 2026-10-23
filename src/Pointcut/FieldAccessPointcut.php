<?php

declare(strict_types=1);

namespace AOP\Pointcut;

use AOP\JoinPoint\JoinPointKind;
use AOP\Pointcut\Pattern\FieldPattern;

/**
 * Shared by get() and set(): a property read or write matching a field pattern. The decision depends on
 * (runtime class, property, kind) only, so it is static and not caller-sensitive.
 */
abstract class FieldAccessPointcut implements PointcutInterface
{
    public function __construct(private readonly FieldPattern $pattern)
    {
    }

    abstract protected function kind(): JoinPointKind;

    /**
     * Class probes (see the engine contract) may carry either field kind, so both pass the prefilter.
     */
    public function matchesClass(?\ReflectionClass $class, StaticMatchContext $ctx): bool
    {
        return (JoinPointKind::FIELD_GET === $ctx->kind || JoinPointKind::FIELD_SET === $ctx->kind) && $this->pattern->matchesClass($class);
    }

    public function matchesMethod(?\ReflectionFunctionAbstract $method, StaticMatchContext $ctx): bool
    {
        return $this->kind() === $ctx->kind && null !== $ctx->property && $this->pattern->matches($ctx->property, $ctx->targetClass);
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
