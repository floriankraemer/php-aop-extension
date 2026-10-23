<?php

declare(strict_types=1);

namespace AOP\Pointcut\Expression;

interface NamedPointcutResolverInterface
{
    /**
     * Returns the pointcut expression of a named pointcut.
     *
     * @param string|null $className  class of a `Class::name()` reference, null for a `name()` reference
     * @param string      $fromAspect aspect class in which the reference occurs
     */
    public function resolve(?string $className, string $methodName, string $fromAspect): string;
}
