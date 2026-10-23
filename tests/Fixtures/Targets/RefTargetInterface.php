<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Targets;

interface RefTargetInterface
{
    public function increment(int &$n): void;

    /**
     * @param list<string> $list
     */
    public function appendAll(array &$list, string ...$items): int;

    public function swap(mixed &$a, mixed &$b): void;

    public function scaleAll(int $factor, int &...$values): void;
}
