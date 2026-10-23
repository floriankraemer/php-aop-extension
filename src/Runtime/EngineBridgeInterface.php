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
     * @param callable(array<string, mixed>): int                                              $matcher
     * @param callable(array<string, mixed>, ?object, array<int|string, mixed>, callable): mixed $dispatcher
     */
    public function register(callable $matcher, callable $dispatcher): void;

    public function unregister(): void;

    /** Forgets every cached matcher decision. */
    public function reset(): void;
}
