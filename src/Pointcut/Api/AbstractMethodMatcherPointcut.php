<?php

declare(strict_types=1);

namespace AOP\Pointcut\Api;

use AOP\JoinPoint\JoinPointKind;
use AOP\Pointcut\FieldInterception;
use AOP\Pointcut\FieldInterceptionAwareInterface;
use AOP\Pointcut\PointcutInterface;
use AOP\Pointcut\RuntimeMatchContext;
use AOP\Pointcut\StaticMatchContext;

/**
 * A pointcut written as a subclass: implement `matches()` (and, for dynamic pointcuts, `matchesArguments()`), optionally
 * restrict it with a ClassFilter. Base of the static and dynamic variants. `PointcutInterface::matchesRuntime()` takes
 * a RuntimeMatchContext, so the Spring-style `matchesRuntime($function, $class, $args)` is called `matchesArguments()` here;
 * `getMethodMatcher()` exposes the pair as a real MethodMatcherInterface.
 */
abstract class AbstractMethodMatcherPointcut implements PointcutInterface, FieldInterceptionAwareInterface
{
    private ClassFilterInterface $classFilter = TrueClassFilter::INSTANCE;

    /** @var list<JoinPointKind>|null */
    private ?array $kinds = null;

    private ?ApiPointcut $delegate = null;

    /**
     * The static decision, taken once per (class, method).
     *
     * @param \ReflectionClass<object>|null $class the runtime class; null for functions
     */
    abstract public function matches(\ReflectionFunctionAbstract $function, ?\ReflectionClass $class): bool;

    /**
     * The per-call decision of a runtime pointcut. Static pointcuts are never asked.
     *
     * @param \ReflectionClass<object>|null $class
     * @param array<int|string, mixed>      $args
     */
    abstract public function matchesArguments(\ReflectionFunctionAbstract $function, ?\ReflectionClass $class, array $args): bool;

    public function getMethodMatcher(): MethodMatcherInterface
    {
        return new PointcutMethodMatcher($this);
    }

    public function setClassFilter(ClassFilterInterface $classFilter): static
    {
        $this->classFilter = $classFilter;
        $this->delegate = null;

        return $this;
    }

    public function getClassFilter(): ClassFilterInterface
    {
        return $this->classFilter;
    }

    /**
     * Also (or only) advise other execution kinds, e.g. `[JoinPointKind::CONSTRUCTOR_EXECUTION]`.
     *
     * @param list<JoinPointKind> $kinds
     */
    public function setKinds(array $kinds): static
    {
        $this->kinds = $kinds;
        $this->delegate = null;

        return $this;
    }

    public function matchesClass(?\ReflectionClass $class, StaticMatchContext $ctx): bool
    {
        return $this->pointcut()->matchesClass($class, $ctx);
    }

    public function matchesMethod(?\ReflectionFunctionAbstract $function, StaticMatchContext $ctx): bool
    {
        return $this->pointcut()->matchesMethod($function, $ctx);
    }

    public function matchesRuntime(RuntimeMatchContext $ctx): bool
    {
        return $this->pointcut()->matchesRuntime($ctx);
    }

    public function bindings(): array
    {
        return [];
    }

    public function isCallerSensitive(): bool
    {
        return false;
    }

    private function pointcut(): ApiPointcut
    {
        return $this->delegate ??= new ApiPointcut($this->classFilter, $this->getMethodMatcher(), $this->kinds);
    }

    public function requiresFieldInterception(): bool
    {
        return $this->pointcut()->requiresFieldInterception();
    }
}
