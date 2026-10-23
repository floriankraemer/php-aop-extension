<?php

declare(strict_types=1);

namespace AOP\Pointcut\Api;

/**
 * Restricts a pointcut to a set of classes (Spring's ClassFilter).
 */
interface ClassFilterInterface
{
    /**
     * @param \ReflectionClass<object> $class the runtime class of the join point's object
     */
    public function matches(\ReflectionClass $class): bool;
}
