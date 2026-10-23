<?php

declare(strict_types=1);

namespace AOP\TargetSource;

/**
 * Holds one target that can be replaced while the application runs (Spring's HotSwappableTargetSource).
 * Calls already running keep the target they got; later calls see the new one.
 */
final class HotSwappableTargetSource implements TargetSourceInterface
{
    private object $target;

    /** @var class-string */
    private readonly string $targetClass;

    /**
     * @param class-string|null $targetClass the type every target must have; default: the class of the initial target
     */
    public function __construct(object $initial, ?string $targetClass = null)
    {
        $this->targetClass = $targetClass ?? $initial::class;
        $this->assertCompatible($initial);
        $this->target = $initial;
    }

    /**
     * @return object the target that was replaced
     *
     * @throws TargetSourceException when the new target is not of the target class
     */
    public function swap(object $new): object
    {
        $this->assertCompatible($new);
        $old = $this->target;
        $this->target = $new;

        return $old;
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
        return $this->target;
    }

    public function releaseTarget(object $target): void
    {
    }

    private function assertCompatible(object $target): void
    {
        if (!$target instanceof $this->targetClass) {
            throw new TargetSourceException(sprintf('Cannot use %s as target: this source holds instances of %s.', $target::class, $this->targetClass));
        }
    }
}
