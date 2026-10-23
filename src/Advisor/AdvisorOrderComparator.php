<?php

declare(strict_types=1);

namespace AOP\Advisor;

/**
 * Sort key: advisor order, source (aspect class, or the id of a programmatic advisor), advice type precedence
 * (programmatic advisors default to AROUND), declaration index.
 * Advisors with an equal order run as blocks (alphabetically by source) instead of interleaving.
 */
final class AdvisorOrderComparator
{
    public function __invoke(Advisor $a, Advisor $b): int
    {
        return [$a->order, $a->source(), $a->adviceType()->precedence(), $a->advice->declarationIndex ?? 0]
            <=> [$b->order, $b->source(), $b->adviceType()->precedence(), $b->advice->declarationIndex ?? 0];
    }
}
