<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Introduction;

final class NeedsArgumentsAuditTrail implements Auditable
{
    public function __construct(public readonly string $required)
    {
    }

    public function recordAudit(string $entry): void
    {
    }

    public function auditTrail(): array
    {
        return [];
    }
}
