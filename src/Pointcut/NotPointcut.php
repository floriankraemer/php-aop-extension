<?php

declare(strict_types=1);

namespace AOP\Pointcut;

use AOP\Exception\BindingException;

final class NotPointcut implements PointcutInterface, ControlFlowAwareInterface
{
    public function __construct(private readonly PointcutInterface $inner)
    {
        if ([] !== $inner->bindings()) {
            throw new BindingException(sprintf('A negated pointcut cannot bind values (%s).', implode(', ', array_keys($inner->bindings()))));
        }
    }

    public function matchesClass(?\ReflectionClass $class, StaticMatchContext $ctx): bool
    {
        return true; // "inner does not match this class" does not imply "inner does not match some method"
    }

    public function matchesMethod(?\ReflectionFunctionAbstract $method, StaticMatchContext $ctx): bool
    {
        // The static part of a runtime child is only a pre-filter, so it cannot be inverted.
        return $this->inner->isRuntime()
            || !($this->inner->matchesClass($ctx->targetClass, $ctx) && $this->inner->matchesMethod($method, $ctx));
    }

    public function isRuntime(): bool
    {
        return $this->inner->isRuntime();
    }

    public function matchesRuntime(RuntimeMatchContext $ctx): bool
    {
        return !RuntimeEvaluation::matches($this->inner, $ctx);
    }

    public function bindings(): array
    {
        return [];
    }

    public function isCallerSensitive(): bool
    {
        return $this->inner->isCallerSensitive();
    }

    public function cflowPointcuts(): array
    {
        return ControlFlowPointcuts::of($this->inner);
    }
}
