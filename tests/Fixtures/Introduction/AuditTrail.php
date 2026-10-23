<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Introduction;

final class AuditTrail implements Auditable
{
    public static int $created = 0;

    /** @var list<string> */
    private array $entries = [];

    public function __construct(private readonly string $prefix = '')
    {
        ++self::$created;
    }

    public function recordAudit(string $entry): void
    {
        $this->entries[] = $this->prefix.$entry;
    }

    public function auditTrail(): array
    {
        return $this->entries;
    }
}
