<?php

declare(strict_types=1);

namespace AopBench;

use AOP\Engine;
use AOP\OriginalCall;

/** Registers the matcher/dispatcher pair of a benchmark state (see docs/benchmarks.md). */
final class Dispatch
{
    public static int $advised = 0;

    public static function register(string $state): void
    {
        if ('miss' === $state) {
            // The matcher is consulted once per function, then the decision is cached.
            Engine::register(static fn (array $joinPoint): int => 0, static fn (): mixed => null);
        } elseif ('pass' === $state) {
            Engine::register(
                static fn (array $joinPoint): int => Engine::ADVISE,
                static function (array $joinPoint, ?object $self, array $args, OriginalCall $original): mixed {
                    ++self::$advised;

                    return $original($args);
                },
            );
        }
    }
}
