<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Targets;

class SelfReferencingBase
{
    public function fromBase(self $other): ?self
    {
        return $other;
    }
}
