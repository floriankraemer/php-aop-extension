<?php

declare(strict_types=1);

namespace AOP\Pointcut;

final class AndPointcut implements PointcutInterface, ControlFlowAwareInterface, FieldInterceptionAwareInterface
{
    public function __construct(private readonly PointcutInterface $left, private readonly PointcutInterface $right)
    {
    }

    public function matchesClass(?\ReflectionClass $class, StaticMatchContext $ctx): bool
    {
        return $this->left->matchesClass($class, $ctx) && $this->right->matchesClass($class, $ctx);
    }

    public function matchesMethod(?\ReflectionFunctionAbstract $method, StaticMatchContext $ctx): bool
    {
        return $this->left->matchesMethod($method, $ctx) && $this->right->matchesMethod($method, $ctx);
    }

    public function isRuntime(): bool
    {
        return $this->left->isRuntime() || $this->right->isRuntime();
    }

    public function matchesRuntime(RuntimeMatchContext $ctx): bool
    {
        // static parts already matched at weave time
        return (!$this->left->isRuntime() || $this->left->matchesRuntime($ctx))
            && (!$this->right->isRuntime() || $this->right->matchesRuntime($ctx));
    }

    public function bindings(): array
    {
        return [...$this->left->bindings(), ...$this->right->bindings()];
    }

    public function isCallerSensitive(): bool
    {
        return $this->left->isCallerSensitive() || $this->right->isCallerSensitive();
    }

    public function cflowPointcuts(): array
    {
        return [...ControlFlowPointcuts::of($this->left), ...ControlFlowPointcuts::of($this->right)];
    }

    public function requiresFieldInterception(): bool
    {
        return FieldInterception::requiredBy($this->left) || FieldInterception::requiredBy($this->right);
    }
}
