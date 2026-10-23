<?php

declare(strict_types=1);

namespace AOP\Aspect;

use AOP\Exception\AspectDefinitionException;

final class ArrayAspectInstanceResolver implements AspectInstanceResolverInterface
{
    /**
     * @var array<class-string, object>
     */
    private array $instances = [];

    /**
     * @param array<class-string, object|\Closure(): object> $aspects a plain object is shared by every scope object, so use closures for pertarget/perthis aspects
     */
    public function __construct(private array $aspects)
    {
    }

    /**
     * @var array<class-string, \WeakMap<object, object>> instances live exactly as long as their scope object
     */
    private array $scoped = [];

    public function get(string $aspectClass, ?object $scope = null): object
    {
        if (null === $scope) {
            return $this->instances[$aspectClass] ??= $this->create($aspectClass);
        }

        /** @var \WeakMap<object, object> $map */
        $map = $this->scoped[$aspectClass] ?? new \WeakMap();
        $this->scoped[$aspectClass] = $map;

        return $map[$scope] ??= $this->create($aspectClass);
    }

    /**
     * @param class-string $aspectClass
     */
    private function create(string $aspectClass): object
    {
        if (!array_key_exists($aspectClass, $this->aspects)) {
            throw new AspectDefinitionException(sprintf('No instance registered for aspect "%s".', $aspectClass));
        }

        $entry = $this->aspects[$aspectClass];
        $instance = $entry instanceof \Closure ? $entry() : $entry;

        if (!$instance instanceof $aspectClass) {
            throw new AspectDefinitionException(sprintf('Instance for aspect "%s" is of type "%s".', $aspectClass, get_debug_type($instance)));
        }

        return $instance;
    }
}
