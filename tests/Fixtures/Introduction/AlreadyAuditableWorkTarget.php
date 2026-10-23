<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Introduction;

/**
 * Matches the introduction's type pattern but implements the interface itself, so nothing is introduced.
 */
class AlreadyAuditableWorkTarget implements Auditable
{
    public function recordAudit(string $entry): void
    {
    }

    public function auditTrail(): array
    {
        return ['own'];
    }
}
