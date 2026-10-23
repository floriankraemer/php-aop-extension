<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Targets;

/**
 * Methods with by-reference parameters; the caller's variables must see the target's writes through a proxy.
 */
class RefTarget implements RefTargetInterface
{
    public function increment(int &$n): void
    {
        ++$n;
    }

    /**
     * @param list<string> $list
     */
    public function appendAll(array &$list, string ...$items): int
    {
        foreach ($items as $item) {
            $list[] = $item;
        }

        return count($list);
    }

    public function swap(mixed &$a, mixed &$b): void
    {
        [$a, $b] = [$b, $a];
    }

    public function scaleAll(int $factor, int &...$values): void
    {
        foreach ($values as &$value) {
            $value *= $factor;
        }
    }
}
