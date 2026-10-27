<?php

declare(strict_types=1);

namespace AopBench\Workload;

final class Failing
{
    public function maybeFail(int $i, bool $fail): int
    {
        if ($fail) {
            throw new \RuntimeException('failed '.$i);
        }

        return $i;
    }

    /** @return \Generator<int, int> */
    public function numbers(int $count): \Generator
    {
        for ($i = 0; $i < $count; ++$i) {
            yield $i;
        }
    }
}
