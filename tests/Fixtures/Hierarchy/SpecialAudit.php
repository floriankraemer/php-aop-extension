<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Hierarchy;

#[\Attribute(\Attribute::TARGET_METHOD)]
final class SpecialAudit extends Audit
{
}
