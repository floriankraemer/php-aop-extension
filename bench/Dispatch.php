<?php

declare(strict_types=1);

namespace AopBench;

use AOP\Attribute\Aspect;
use AOP\Attribute\Before;
use AOP\Engine;
use AOP\OriginalCall;
use AOP\Weaver;

/** Registers the matcher/dispatcher pair of a benchmark state (see docs/benchmarks.md). */
final class Dispatch
{
    public static int $advised = 0;

    public static function register(string $state): void
    {
        if ('advised' === $state) {
            // The real runtime: a before advice on every function and method, through the Weaver.
            Weaver::fromAspects([new CountingAspect()])->enable();
        } elseif ('miss' === $state) {
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

#[Aspect]
final class CountingAspect
{
    #[Before('execution(* *(..)) || execution(* *::*(..))')]
    public function count(): void
    {
        ++Dispatch::$advised;
    }
}
