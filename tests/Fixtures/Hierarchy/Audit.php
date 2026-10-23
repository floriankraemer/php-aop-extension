<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Hierarchy;

#[\Attribute(\Attribute::TARGET_METHOD)]
class Audit
{
    public function __construct(public string $action = '')
    {
    }
}
