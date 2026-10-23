<?php

declare(strict_types=1);

namespace AOP\Runtime;

use AOP\Aspect\AspectInstanceResolverInterface;
use AOP\Aspect\AspectScope;
use AOP\Exception\AspectDefinitionException;
use AOP\JoinPoint\JoinPointInterface;

/**
 * Holds the aspect objects of a Weaver and creates the instances of the non-singleton instantiation models by
 * cloning the object that was registered (as it was when registered).
 *
 * Join points without the object an instance model needs (static members and functions have no `this`/`target`)
 * use the registered object itself.
 */
final class ScopedAspectResolver implements AspectInstanceResolverInterface, AdviceExecutionWrapper
{
    /** @var array<class-string, array{object: ?object, prototype: ?object, factory: ?\Closure, scope: AspectScope}> */
    private array $aspects = [];

    /** @var array<class-string, \WeakMap<object, object>> */
    private array $byObject = [];

    /** @var array<class-string, array<string, object>> */
    private array $byType = [];

    private ?\Closure $wrapper = null;

    public function __construct(private readonly ControlFlowStack $flow)
    {
    }

    /**
     * @param \Closure(JoinPointInterface, class-string, object, string, array<int|string, mixed>, \Closure): mixed $wrapper
     */
    public function wrapWith(\Closure $wrapper): void
    {
        $this->wrapper = $wrapper;
    }

    /**
     * @param class-string              $aspectClass
     * @param object|\Closure(): object $aspect      the instance (kept as the singleton and as the clone prototype), or a factory
     *                                               called lazily once for the shared instance and once per scope key
     */
    public function register(string $aspectClass, object $aspect, AspectScope $scope): void
    {
        $this->remove($aspectClass);
        $this->aspects[$aspectClass] = $aspect instanceof \Closure
            ? ['object' => null, 'prototype' => null, 'factory' => $aspect, 'scope' => $scope]
            : ['object' => $aspect, 'prototype' => AspectScope::SINGLETON === $scope ? $aspect : clone $aspect, 'factory' => null, 'scope' => $scope];
    }

    /**
     * @param class-string $aspectClass
     */
    public function remove(string $aspectClass): void
    {
        unset($this->aspects[$aspectClass], $this->byObject[$aspectClass], $this->byType[$aspectClass]);
    }

    /**
     * @param class-string $aspectClass
     */
    public function has(string $aspectClass): bool
    {
        return isset($this->aspects[$aspectClass]);
    }

    public function get(string $aspectClass, ?object $scope = null): object
    {
        $entry = $this->aspects[$aspectClass] ?? throw new AspectDefinitionException(sprintf('No instance registered for aspect "%s".', $aspectClass));
        $shared = $this->shared($aspectClass);
        if (null === $scope) {
            return $shared;
        }

        switch ($entry['scope']) {
            case AspectScope::SINGLETON:
                return $shared;
            case AspectScope::PER_THIS:
            case AspectScope::PER_TARGET:
                return $this->forKey($aspectClass, $scope);
            case AspectScope::PER_CFLOW:
            case AspectScope::PER_CFLOW_BELOW:
                $token = $this->flow->activeToken($aspectClass, AspectScope::PER_CFLOW_BELOW === $entry['scope']);

                return null === $token ? $shared : $this->forKey($aspectClass, $token->key);
            case AspectScope::PER_TYPE_WITHIN:
                $type = $scope instanceof JoinPointInterface ? self::typeWithin($scope) : null;
                if (null === $type) {
                    return $shared;
                }

                return $this->byType[$aspectClass][$type] ??= $this->create($aspectClass);
        }
    }

    public function wrapAdvice(JoinPointInterface $joinPoint, string $aspectClass, object $aspect, string $method, array $values, \Closure $call): mixed
    {
        return null === $this->wrapper ? $call($values) : ($this->wrapper)($joinPoint, $aspectClass, $aspect, $method, $values, $call);
    }

    /**
     * @param class-string $aspectClass
     */
    private function shared(string $aspectClass): object
    {
        $entry = &$this->aspects[$aspectClass];

        return $entry['object'] ??= $this->make($aspectClass);
    }

    /**
     * A new instance for a scope key: a clone of the registered object, or a call of the factory.
     *
     * @param class-string $aspectClass
     */
    private function create(string $aspectClass): object
    {
        $entry = $this->aspects[$aspectClass];

        return null !== $entry['prototype'] ? clone $entry['prototype'] : $this->make($aspectClass);
    }

    /**
     * @param class-string $aspectClass
     */
    private function make(string $aspectClass): object
    {
        $factory = $this->aspects[$aspectClass]['factory'] ?? null;
        $instance = null === $factory ? null : $factory();
        if (!$instance instanceof $aspectClass) {
            throw new AspectDefinitionException(sprintf('The factory of aspect "%s" returned %s.', $aspectClass, get_debug_type($instance)));
        }

        return $instance;
    }

    /**
     * @param class-string $aspectClass
     */
    private function forKey(string $aspectClass, object $key): object
    {
        /** @var \WeakMap<object, object> $map */
        $map = $this->byObject[$aspectClass] ?? new \WeakMap();
        $this->byObject[$aspectClass] = $map;

        return $map[$key] ??= $this->create($aspectClass);
    }

    /**
     * The type the join point lexically belongs to: the declaring class of executed code, the caller's class for call sites.
     */
    private static function typeWithin(JoinPointInterface $joinPoint): ?string
    {
        if ($joinPoint->getKind()->isCall()) {
            return $joinPoint->getEnclosingStaticPart()?->signature->getDeclaringTypeName();
        }

        return $joinPoint->getSignature()->getDeclaringTypeName();
    }
}
