<?php

declare(strict_types=1);

namespace AOP\TargetSource;

/**
 * Where the object behind an advised call comes from (Spring's TargetSource).
 */
interface TargetSourceInterface
{
    /**
     * @return class-string the class (or interface) every target is an instance of
     */
    public function getTargetClass(): string;

    /**
     * True when getTarget() always returns the same object, so callers need not release it.
     */
    public function isStatic(): bool;

    /**
     * @throws TargetSourceException when no target can be provided (e.g. an exhausted pool)
     */
    public function getTarget(): object;

    /**
     * Gives a target obtained from getTarget() back.
     *
     * @throws TargetSourceException when the object did not come from this source
     */
    public function releaseTarget(object $target): void;
}
