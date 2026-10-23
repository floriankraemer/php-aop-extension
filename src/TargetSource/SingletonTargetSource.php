<?php

declare(strict_types=1);

namespace AOP\TargetSource;

final readonly class SingletonTargetSource implements TargetSourceInterface
{
    public function __construct(private object $target)
    {
    }

    public function getTargetClass(): string
    {
        return $this->target::class;
    }

    public function isStatic(): bool
    {
        return true;
    }

    public function getTarget(): object
    {
        return $this->target;
    }

    public function releaseTarget(object $target): void
    {
    }
}
