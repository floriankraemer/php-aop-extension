<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Introduction;

/**
 * Has a private `recordAudit()` and does not implement `Auditable`.
 */
class ClashingPrivateTarget
{
    public function run(): string
    {
        return $this->recordAudit('internal');
    }

    private function recordAudit(string $entry): string
    {
        return 'private '.$entry;
    }
}
