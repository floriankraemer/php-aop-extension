<?php

declare(strict_types=1);

namespace AOP\Pointcut\Api;

use AOP\Pointcut\AndPointcut;
use AOP\Pointcut\ControlFlowAwareInterface;
use AOP\Pointcut\FieldInterception;
use AOP\Pointcut\FieldInterceptionAwareInterface;
use AOP\Pointcut\ControlFlowPointcuts;
use AOP\Pointcut\OrPointcut;
use AOP\Pointcut\PointcutInterface;
use AOP\Pointcut\RuntimeMatchContext;
use AOP\Pointcut\StaticMatchContext;

/**
 * Builds a pointcut step by step with union() and intersection() (Spring's ComposablePointcut).
 *
 * Starts as "match everything". Every step is applied to the pointcut built so far:
 * `intersection(x)` keeps what both match, `union(x)` keeps what either matches. A ClassFilter or MethodMatcher
 * operand stands for the pointcut that applies it to all classes / all methods.
 *
 * Unlike Spring, `union()` combines whole pointcuts, so `union(filter)` adds every method of the classes the
 * filter accepts, not "the same methods on more classes".
 *
 * The methods mutate and return `$this` so calls chain.
 */
final class ComposablePointcut implements PointcutInterface, ControlFlowAwareInterface, FieldInterceptionAwareInterface
{
    private PointcutInterface $root;

    public function __construct(?PointcutInterface $pointcut = null)
    {
        $this->root = $pointcut ?? TruePointcut::INSTANCE;
    }

    public function union(ClassFilterInterface|MethodMatcherInterface|PointcutInterface $operand): self
    {
        $this->root = new OrPointcut($this->root, self::pointcut($operand));

        return $this;
    }

    public function intersection(ClassFilterInterface|MethodMatcherInterface|PointcutInterface $operand): self
    {
        $this->root = new AndPointcut($this->root, self::pointcut($operand));

        return $this;
    }

    public function matchesClass(?\ReflectionClass $class, StaticMatchContext $ctx): bool
    {
        return $this->root->matchesClass($class, $ctx);
    }

    public function matchesMethod(?\ReflectionFunctionAbstract $function, StaticMatchContext $ctx): bool
    {
        return $this->root->matchesMethod($function, $ctx);
    }

    public function isRuntime(): bool
    {
        return $this->root->isRuntime();
    }

    public function matchesRuntime(RuntimeMatchContext $ctx): bool
    {
        return $this->root->matchesRuntime($ctx);
    }

    public function bindings(): array
    {
        return $this->root->bindings();
    }

    public function isCallerSensitive(): bool
    {
        return $this->root->isCallerSensitive();
    }

    public function cflowPointcuts(): array
    {
        return ControlFlowPointcuts::of($this->root);
    }

    private static function pointcut(ClassFilterInterface|MethodMatcherInterface|PointcutInterface $operand): PointcutInterface
    {
        return match (true) {
            $operand instanceof PointcutInterface => $operand,
            $operand instanceof ClassFilterInterface => new ApiPointcut($operand, TrueMethodMatcher::INSTANCE),
            default => new ApiPointcut(TrueClassFilter::INSTANCE, $operand),
        };
    }

    public function requiresFieldInterception(): bool
    {
        return FieldInterception::requiredBy($this->root);
    }
}
