<?php

declare(strict_types=1);

namespace AOP\Pointcut\Api;

/**
 * Spring's ClassFilter.TRUE.
 */
enum TrueClassFilter implements ClassFilterInterface
{
    case INSTANCE;

    public function matches(\ReflectionClass $class): bool
    {
        return true;
    }
}
