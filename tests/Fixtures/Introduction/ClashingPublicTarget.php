<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Introduction;

/**
 * Declares `recordAudit()` with a signature incompatible with `Auditable` and does not implement it.
 */
class ClashingPublicTarget
{
    public function recordAudit(int $count): int
    {
        return $count;
    }
}
