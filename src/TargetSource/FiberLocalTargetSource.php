<?php

declare(strict_types=1);

namespace AOP\TargetSource;

/**
 * One target per Fiber, created on first use (Spring's ThreadLocalTargetSource, with Fibers as the "threads").
 * Code outside any Fiber shares one target. A Fiber's target is dropped with the Fiber.
 */
final class FiberLocalTargetSource implements TargetSourceInterface
{
    /** @var \WeakMap<object, object> */
    private \WeakMap $perFiber;

    private ?object $main = null;

    private int $created = 0;

    private int $hits = 0;

    /**
     * @param class-string       $targetClass
     * @param \Closure(): object $factory
     */
    public function __construct(private readonly string $targetClass, private readonly \Closure $factory)
    {
        $this->perFiber = new \WeakMap();
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
        ++$this->hits;
        $fiber = \Fiber::getCurrent();
        if (null === $fiber) {
            return $this->main ??= $this->create();
        }

        return $this->perFiber[$fiber] ??= $this->create();
    }

    public function releaseTarget(object $target): void
    {
    }

    /**
     * How many targets were created (one per Fiber that used the source).
     */
    public function getCreatedCount(): int
    {
        return $this->created;
    }

    /**
     * How many times a target was requested.
     */
    public function getInvocationCount(): int
    {
        return $this->hits;
    }

    private function create(): object
    {
        ++$this->created;

        return TargetFactory::create($this->factory, $this->targetClass);
    }
}
