<?php

declare(strict_types=1);

namespace AOP\TargetSource;

/**
 * A bounded pool of targets (Spring's CommonsPool2TargetSource). Targets are created on demand up to `maxSize`
 * and reused after `releaseTarget()`. PHP has no threads to wait on, so an exhausted pool throws
 * PoolExhaustedException at once (a Fiber that must wait can catch it and suspend).
 */
final class PoolingTargetSource implements TargetSourceInterface
{
    /** @var list<object> */
    private array $idle = [];

    /** @var \SplObjectStorage<object, null> */
    private \SplObjectStorage $active;

    private int $created = 0;

    private int $borrowed = 0;

    private int $released = 0;

    private int $exhausted = 0;

    /**
     * @param class-string       $targetClass
     * @param \Closure(): object $factory
     */
    public function __construct(
        private readonly string $targetClass,
        private readonly \Closure $factory,
        private readonly int $maxSize = 25,
    ) {
        if ($maxSize < 1) {
            throw new \InvalidArgumentException(sprintf('The pool size must be at least 1, %d given.', $maxSize));
        }
        $this->active = new \SplObjectStorage();
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
        $target = array_pop($this->idle);
        if (null === $target) {
            if (count($this->active) >= $this->maxSize) {
                ++$this->exhausted;

                throw new PoolExhaustedException(sprintf('The pool of %s is exhausted: all %d targets are in use.', $this->targetClass, $this->maxSize));
            }
            $target = TargetFactory::create($this->factory, $this->targetClass);
            ++$this->created;
        }
        $this->active->offsetSet($target, null);
        ++$this->borrowed;

        return $target;
    }

    public function releaseTarget(object $target): void
    {
        if (!$this->active->offsetExists($target)) {
            throw new TargetSourceException(sprintf('Cannot release %s: it is not borrowed from this pool.', $target::class));
        }
        $this->active->offsetUnset($target);
        $this->idle[] = $target;
        ++$this->released;
    }

    public function stats(): PoolStats
    {
        return new PoolStats($this->maxSize, count($this->active), count($this->idle), $this->created, $this->borrowed, $this->released, $this->exhausted);
    }
}
