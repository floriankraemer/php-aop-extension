<?php

declare(strict_types=1);

namespace AopBench;

use PhpBench\Attributes\BeforeMethods;

use function AopBench\Workload\escapeTime;
use function AopBench\Workload\square;

/** Mandelbrot: a JIT-friendly kernel, once without and once with a function call in the hot loop. */
#[BeforeMethods('assertEnvironment')]
final class NumericBench
{
    use Environment;

    private const SIZE = 64;
    private const MAX_ITERATIONS = 100;

    public function benchInline(): void
    {
        $sum = 0;
        for ($y = 0; $y < self::SIZE; ++$y) {
            for ($x = 0; $x < self::SIZE; ++$x) {
                $cr = $x / self::SIZE * 3.0 - 2.0;
                $ci = $y / self::SIZE * 2.0 - 1.0;
                $zr = 0.0;
                $zi = 0.0;
                for ($i = 0; $i < self::MAX_ITERATIONS; ++$i) {
                    $zr2 = $zr * $zr;
                    $zi2 = $zi * $zi;
                    if ($zr2 + $zi2 > 4.0) {
                        break;
                    }
                    $zi = 2.0 * $zr * $zi + $ci;
                    $zr = $zr2 - $zi2 + $cr;
                }
                $sum += $i;
            }
        }
        $this->consume($sum);
    }

    public function benchCallPerPixel(): void
    {
        $sum = 0;
        for ($y = 0; $y < self::SIZE; ++$y) {
            for ($x = 0; $x < self::SIZE; ++$x) {
                $sum += escapeTime($x / self::SIZE * 3.0 - 2.0, $y / self::SIZE * 2.0 - 1.0, self::MAX_ITERATIONS);
            }
        }
        $this->consume($sum);
    }

    public function benchCallPerIteration(): void
    {
        $sum = 0;
        for ($y = 0; $y < self::SIZE; ++$y) {
            for ($x = 0; $x < self::SIZE; ++$x) {
                $cr = $x / self::SIZE * 3.0 - 2.0;
                $ci = $y / self::SIZE * 2.0 - 1.0;
                $zr = 0.0;
                $zi = 0.0;
                for ($i = 0; $i < self::MAX_ITERATIONS; ++$i) {
                    $zr2 = square($zr);
                    $zi2 = square($zi);
                    if ($zr2 + $zi2 > 4.0) {
                        break;
                    }
                    $zi = 2.0 * $zr * $zi + $ci;
                    $zr = $zr2 - $zi2 + $cr;
                }
                $sum += $i;
            }
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
