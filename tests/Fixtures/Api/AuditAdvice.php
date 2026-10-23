<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Api;

/**
 * A custom advice type: no advice interface, so it needs a registered adapter.
 */
final class AuditAdvice
{
    /** @var list<string> */
    public array $audited = [];

    public function audit(string $what): void
    {
        $this->audited[] = $what;
    }
}
