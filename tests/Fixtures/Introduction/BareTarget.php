<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Introduction;

/**
 * Matched by an introduction only: no advice applies to its methods.
 */
class BareTarget
{
    public function value(): string
    {
        return 'bare';
    }
}
