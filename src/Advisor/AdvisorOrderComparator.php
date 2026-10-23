<?php

declare(strict_types=1);

namespace AOP\Advisor;

/**
 * Sort key: aspect order, aspect class, advice type precedence, declaration index.
 * Aspects with an equal order run as blocks (alphabetically by class name) instead of interleaving.
 */
final class AdvisorOrderComparator
{
    public function __invoke(Advisor $a, Advisor $b): int
    {
        return [$a->order, $a->advice->aspectClass, $a->advice->type->precedence(), $a->advice->declarationIndex]
            <=> [$b->order, $b->advice->aspectClass, $b->advice->type->precedence(), $b->advice->declarationIndex];
    }
}
