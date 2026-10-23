<?php

declare(strict_types=1);

namespace AOP\Runtime;

/**
 * Seam between the PHP runtime and the engine: NativeEngineBridge talks to the C extension (\AOP\Engine),
 * tests use AOP\Tests\Support\FakeEngine. See docs/internals/contract.md.
 */
interface EngineBridgeInterface
{
    /**
     * @param callable(array<string, mixed>): int                                                $matcher
     * @param callable(array<string, mixed>, ?object, array<int|string, mixed>, callable): mixed $dispatcher
     */
    public function register(callable $matcher, callable $dispatcher): void;

    public function unregister(): void;

    /** Forgets every cached matcher decision. */
    public function reset(): void;

    /**
     * False when there is no engine (extension not loaded) or interception is switched off (`aop.enable=0`):
     * register() reports that. When true, fieldInterceptionAvailable() tells which backend runs.
     */
    public function interceptionActive(): bool;

    /**
     * False when the engine cannot intercept field reads/writes and `initialization` of classes without constructor
     * (`aop.interception=woven`, the default), or when there is no engine.
     */
    public function fieldInterceptionAvailable(): bool;
}
