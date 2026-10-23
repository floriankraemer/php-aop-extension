<?php

declare(strict_types=1);

namespace AOP\Pointcut;

use AOP\Pointcut\Pattern\NamePattern;

final class ServicePointcut implements PointcutInterface
{
    public function __construct(private readonly NamePattern $pattern)
    {
    }

    public function matchesClass(\ReflectionClass $class, StaticMatchContext $ctx): bool
    {
        return null !== $ctx->serviceName && $this->pattern->matches($ctx->serviceName);
    }

    public function matchesMethod(\ReflectionMethod $method, StaticMatchContext $ctx): bool
    {
        return $this->matchesClass($ctx->targetClass, $ctx);
    }

    public function isRuntime(): bool
    {
        return false;
    }

    public function matchesRuntime(RuntimeMatchContext $ctx): bool
    {
        return true;
    }

    public function bindings(): array
    {
        return [];
    }
}
