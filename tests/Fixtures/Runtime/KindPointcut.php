<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Runtime;

use AOP\JoinPoint\JoinPointKind;
use AOP\Pointcut\PointcutInterface;
use AOP\Pointcut\RuntimeMatchContext;
use AOP\Pointcut\StaticMatchContext;

/**
 * Matches join points of the given kinds by member name (and optionally the calling function), standing in for the
 * `call()` designator in tests of the runtime.
 */
final class KindPointcut implements PointcutInterface
{
    /**
     * @param list<JoinPointKind> $kinds
     */
    public function __construct(
        private readonly array $kinds,
        private readonly string $name = '*',
        private readonly ?string $callerFunction = null,
        private readonly bool $sensitive = false,
        private readonly ?string $service = null,
    ) {
    }

    public function matchesClass(?\ReflectionClass $class, StaticMatchContext $ctx): bool
    {
        return \in_array($ctx->kind, $this->kinds, true);
    }

    public function matchesMethod(?\ReflectionFunctionAbstract $function, StaticMatchContext $ctx): bool
    {
        if (!\in_array($ctx->kind, $this->kinds, true)) {
            return false;
        }
        if (null === $function) {
            return JoinPointKind::INITIALIZATION === $ctx->kind;
        }
        if ('*' !== $this->name && $function->getName() !== $this->name) {
            return false;
        }
        if (null !== $this->service && $ctx->serviceName !== $this->service) {
            return false;
        }

        return null === $this->callerFunction || $ctx->caller?->function?->getName() === $this->callerFunction;
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

    public function isCallerSensitive(): bool
    {
        return $this->sensitive;
    }
}
