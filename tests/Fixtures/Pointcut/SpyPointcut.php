<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Pointcut;

use AOP\Pointcut\PointcutInterface;
use AOP\Pointcut\RuntimeMatchContext;
use AOP\Pointcut\StaticMatchContext;

/**
 * Pointcut that records how often each method was consulted.
 */
final class SpyPointcut implements PointcutInterface
{
    public int $classCalls = 0;
    public int $methodCalls = 0;
    public int $runtimeCalls = 0;

    public function __construct(
        private readonly bool $class = true,
        private readonly bool $method = true,
        private readonly bool $runtime = false,
        private readonly bool $runtimeResult = true,
    ) {
    }

    public function matchesClass(\ReflectionClass $class, StaticMatchContext $ctx): bool
    {
        ++$this->classCalls;

        return $this->class;
    }

    public function matchesMethod(\ReflectionMethod $method, StaticMatchContext $ctx): bool
    {
        ++$this->methodCalls;

        return $this->method;
    }

    public function isRuntime(): bool
    {
        return $this->runtime;
    }

    public function matchesRuntime(RuntimeMatchContext $ctx): bool
    {
        ++$this->runtimeCalls;

        return $this->runtimeResult;
    }

    public function bindings(): array
    {
        return [];
    }
}
