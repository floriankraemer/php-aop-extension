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
    /** @var array<class-string, array{object: object, prototype: object, scope: AspectScope}> */
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

    public function register(object $aspect, AspectScope $scope): void
    {
        $this->remove($aspect::class);
        $this->aspects[$aspect::class] = ['object' => $aspect, 'prototype' => AspectScope::SINGLETON === $scope ? $aspect : clone $aspect, 'scope' => $scope];
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
        if (null === $scope) {
            return $entry['object'];
        }

        switch ($entry['scope']) {
            case AspectScope::SINGLETON:
                return $entry['object'];
            case AspectScope::PER_THIS:
            case AspectScope::PER_TARGET:
                return $this->forKey($aspectClass, $scope, $entry['prototype']);
            case AspectScope::PER_CFLOW:
            case AspectScope::PER_CFLOW_BELOW:
                $token = $this->flow->activeToken($aspectClass, AspectScope::PER_CFLOW_BELOW === $entry['scope']);

                return null === $token ? $entry['object'] : $this->forKey($aspectClass, $token->key, $entry['prototype']);
            case AspectScope::PER_TYPE_WITHIN:
                $type = $scope instanceof JoinPointInterface ? self::typeWithin($scope) : null;
                if (null === $type) {
                    return $entry['object'];
                }

                return $this->byType[$aspectClass][$type] ??= clone $entry['prototype'];
        }
    }

    public function wrapAdvice(JoinPointInterface $joinPoint, string $aspectClass, object $aspect, string $method, array $values, \Closure $call): mixed
    {
        return null === $this->wrapper ? $call($values) : ($this->wrapper)($joinPoint, $aspectClass, $aspect, $method, $values, $call);
    }

    /**
     * @param class-string $aspectClass
     */
    private function forKey(string $aspectClass, object $key, object $prototype): object
    {
        /** @var \WeakMap<object, object> $map */
        $map = $this->byObject[$aspectClass] ?? new \WeakMap();
        $this->byObject[$aspectClass] = $map;

        return $map[$key] ??= clone $prototype;
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
