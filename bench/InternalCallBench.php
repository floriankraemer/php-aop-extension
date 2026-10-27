<?php

declare(strict_types=1);

namespace AopBench;

use PhpBench\Attributes\BeforeMethods;

/** Calls of internal functions: they reach the engine through zend_execute_internal. */
#[BeforeMethods('assertEnvironment')]
final class InternalCallBench
{
    use Environment;

    private const CALLS = 100_000;

    public function benchStrlenAbs(): void
    {
        $sum = 0;
        $text = 'benchmark';
        for ($i = 0; $i < self::CALLS; ++$i) {
            $sum += strlen($text) + abs($i - 50);
        }
        $this->consume($sum);
    }

    public function benchArrayMap(): void
    {
        $values = range(1, 100);
        $sum = 0;
        for ($i = 0; $i < self::CALLS / 100; ++$i) {
            $sum += array_sum(array_map(static fn (int $v): int => $v + 1, $values));
        }
        $this->consume($sum);
    }

    private function consume(int $value): void
    {
        if (-1 === $value) {
            throw new \LogicException('unreachable');
        }
    }
}
