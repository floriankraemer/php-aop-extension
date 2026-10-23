<?php

declare(strict_types=1);

namespace AOP\TargetSource;

/**
 * A new target for every call (Spring's PrototypeTargetSource). Released targets are simply dropped.
 */
final class PrototypeTargetSource implements TargetSourceInterface
{
    private int $created = 0;

    /**
     * @param class-string       $targetClass
     * @param \Closure(): object $factory
     */
    public function __construct(private readonly string $targetClass, private readonly \Closure $factory)
    {
    }

    public function getTargetClass(): string
    {
        return $this->targetClass;
    }

    public function isStatic(): bool
    {
        return false;
    }

    public function getTarget(): object
    {
        ++$this->created;

        return TargetFactory::create($this->factory, $this->targetClass);
    }

    public function releaseTarget(object $target): void
    {
    }

    public function getCreatedCount(): int
    {
        return $this->created;
    }
}
