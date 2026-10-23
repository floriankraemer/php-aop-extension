<?php

declare(strict_types=1);

namespace AOP\Aspect;

interface AspectInstanceResolverInterface
{
    /**
     * @param class-string $aspectClass
     * @param object|null  $scope       the target (`pertarget`) or proxy (`perthis`) the instance belongs to; null for singletons
     */
    public function get(string $aspectClass, ?object $scope = null): object;
}
