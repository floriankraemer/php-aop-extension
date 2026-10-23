<?php

declare(strict_types=1);

namespace AOP\Pointcut;

interface PointcutInterface
{
    /**
     * @param \ReflectionClass<object> $class
     */
    public function matchesClass(\ReflectionClass $class, StaticMatchContext $ctx): bool;

    public function matchesMethod(\ReflectionMethod $method, StaticMatchContext $ctx): bool;

    /**
     * True when matchesRuntime() must be consulted for each invocation.
     */
    public function isRuntime(): bool;

    public function matchesRuntime(RuntimeMatchContext $ctx): bool;

    /**
     * @return array<string, BindingSpec> binding name => spec
     */
    public function bindings(): array;
}
