<?php

/**
 * Signatures of the classes registered by the aop extension. Used by static analysis and IDEs only;
 * never include this file at runtime. The behaviour is specified in docs/internals/contract.md.
 *
 * @generate-class-entries
 */

namespace AOP;

final class Engine
{
    /** Dispatch the join point to the dispatcher. */
    public const int ADVISE = 1;
    /** Dispatch the join point although no advice applies; it feeds cflow() tracking. */
    public const int TRACK = 2;
    /** The decision depends on the calling code; the matcher is asked again per caller function. */
    public const int CALLER_SENSITIVE = 4;

    private function __construct() {}

    /**
     * @param callable(array<string, mixed>): int                                                  $matcher
     * @param callable(array<string, mixed>, ?object, array<int|string, mixed>, OriginalCall): mixed $dispatcher
     */
    public static function register(callable $matcher, callable $dispatcher): void {}

    public static function unregister(): void {}

    public static function isRegistered(): bool {}

    /** Forgets every cached matcher decision. */
    public static function reset(): void {}

    /**
     * @param list<array{typePattern: string, interface: class-string, defaultImpl: class-string}> $rules
     */
    public static function setIntroductions(array $rules): void {}

    public static function fieldInterceptionAvailable(): bool {}
}

final class OriginalCall
{
    private function __construct() {}

    /**
     * Runs the intercepted code with these arguments (int keys positional, string keys named), bypassing
     * interception of this one call.
     *
     * @param array<int|string, mixed> $args
     */
    public function __invoke(array $args): mixed {}
}
