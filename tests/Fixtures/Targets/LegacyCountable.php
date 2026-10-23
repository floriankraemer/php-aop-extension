<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Targets;

/**
 * Pre-8.1 style implementation of an internal interface without the tentative return type.
 */
class LegacyCountable implements \Countable
{
    #[\ReturnTypeWillChange]
    public function count()
    {
        return 3;
    }
}
