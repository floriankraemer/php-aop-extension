<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Introduction;

interface Auditable
{
    public function recordAudit(string $entry): void;

    /**
     * @return list<string>
     */
    public function auditTrail(): array;
}
