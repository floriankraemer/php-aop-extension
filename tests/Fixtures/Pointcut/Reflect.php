<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Pointcut;

final class Reflect
{
    /**
     * @param class-string $class
     *
     * @return \ReflectionClass<object>
     */
    public static function cls(string $class): \ReflectionClass
    {
        return new \ReflectionClass($class);
    }
}
