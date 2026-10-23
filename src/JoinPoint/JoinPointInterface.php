<?php

declare(strict_types=1);

namespace AOP\JoinPoint;

interface JoinPointInterface
{
    /**
     * @return array<int|string, mixed>
     */
    public function getArgs(): array;

    /**
     * The proxy the call was made on.
     */
    public function getThis(): object;

    /**
     * The wrapped target instance.
     */
    public function getTarget(): object;

    public function getSignature(): MethodSignature;

    public function toShortString(): string;
}
