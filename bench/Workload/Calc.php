<?php

declare(strict_types=1);

namespace AopBench\Workload;

final class Calc
{
    public function add(int $a, int $b): int
    {
        return $a + $b;
    }

    public static function addStatic(int $a, int $b): int
    {
        return $a + $b;
    }
}
