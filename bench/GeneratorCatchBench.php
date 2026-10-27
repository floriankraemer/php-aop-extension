<?php

declare(strict_types=1);

namespace AopBench;

use AopBench\Workload\Failing;
use PhpBench\Attributes\BeforeMethods;

/** Generators and try/catch: the woven backend also weaves catch blocks. */
#[BeforeMethods('assertEnvironment')]
final class GeneratorCatchBench
{
    use Environment;

    private const ITEMS = 50_000;

    public function benchGenerator(): void
    {
        $sum = 0;
        foreach ((new Failing())->numbers(self::ITEMS) as $number) {
            $sum += $number;
        }
        $this->consume($sum);
    }

    public function benchTryCatchNeverThrows(): void
    {
        $failing = new Failing();
        $sum = 0;
        for ($i = 0; $i < self::ITEMS; ++$i) {
            try {
                $sum += $failing->maybeFail($i, false);
            } catch (\RuntimeException) {
                --$sum;
            }
        }
        $this->consume($sum);
    }

    public function benchTryCatchThrowsOften(): void
    {
        $failing = new Failing();
        $sum = 0;
        for ($i = 0; $i < self::ITEMS / 5; ++$i) {
            try {
                $sum += $failing->maybeFail($i, 0 === $i % 4);
            } catch (\RuntimeException) {
                --$sum;
            }
        }
        $this->consume($sum);
    }

    private function consume(int $value): void
    {
        if (PHP_INT_MIN === $value) {
            throw new \LogicException('unreachable');
        }
    }
}
