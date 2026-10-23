<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Pointcut;

use AOP\Pointcut\PointcutInterface;
use AOP\Pointcut\RuntimeMatchContext;
use AOP\Pointcut\StaticMatchContext;

class StubPointcut implements PointcutInterface
{
    public function __construct(
        private readonly bool $class = true,
        private readonly bool $method = true,
        private readonly bool $runtime = false,
        private readonly bool $callerSensitive = false,
    ) {
    }

    public function matchesClass(?\ReflectionClass $class, StaticMatchContext $ctx): bool
    {
        return $this->class;
    }

    public function matchesMethod(?\ReflectionFunctionAbstract $method, StaticMatchContext $ctx): bool
    {
        return $this->method;
    }

    public function isRuntime(): bool
    {
        return $this->runtime;
    }

    public function matchesRuntime(RuntimeMatchContext $ctx): bool
    {
        return true;
    }

    public function bindings(): array
    {
        return [];
    }

    public function isCallerSensitive(): bool
    {
        return $this->callerSensitive;
    }
}
