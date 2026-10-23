<?php

declare(strict_types=1);

namespace AOP\TargetSource;

/**
 * @internal
 */
final class TargetFactory
{
    private function __construct()
    {
    }

    /**
     * @param \Closure(): object $factory
     * @param class-string       $class
     */
    public static function create(\Closure $factory, string $class): object
    {
        $target = $factory();
        if (!$target instanceof $class) {
            throw new TargetSourceException(sprintf('The target factory returned %s, expected an instance of %s.', get_debug_type($target), $class));
        }

        return $target;
    }
}
