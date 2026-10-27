<?php

declare(strict_types=1);

namespace AopBench;

use AopBench\Workload\Calc;
use PhpBench\Attributes\BeforeMethods;

use function AopBench\Workload\add;

/** Tight calls of a tiny typed function: the worst case for the per-call cost. */
#[BeforeMethods('assertEnvironment')]
final class CallBench
{
    use Environment;

    private const CALLS = 100_000;

    public function benchFunction(): void
    {
        $sum = 0;
        for ($i = 0; $i < self::CALLS; ++$i) {
            $sum = add($sum, $i);
        }
        $this->consume($sum);
    }

    public function benchInstanceMethod(): void
    {
        $calc = new Calc();
        $sum = 0;
        for ($i = 0; $i < self::CALLS; ++$i) {
            $sum = $calc->add($sum, $i);
        }
        $this->consume($sum);
    }

    public function benchStaticMethod(): void
    {
        $sum = 0;
        for ($i = 0; $i < self::CALLS; ++$i) {
            $sum = Calc::addStatic($sum, $i);
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
