<?php

declare(strict_types=1);

namespace AopBench\Workload;

function add(int $a, int $b): int
{
    return $a + $b;
}

function square(float $x): float
{
    return $x * $x;
}

function escapeTime(float $cr, float $ci, int $maxIterations): int
{
    $zr = 0.0;
    $zi = 0.0;
    for ($i = 0; $i < $maxIterations; ++$i) {
        $zr2 = $zr * $zr;
        $zi2 = $zi * $zi;
        if ($zr2 + $zi2 > 4.0) {
            return $i;
        }
        $zi = 2.0 * $zr * $zi + $ci;
        $zr = $zr2 - $zi2 + $cr;
    }

    return $maxIterations;
}
