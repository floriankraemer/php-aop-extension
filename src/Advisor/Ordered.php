<?php

declare(strict_types=1);

namespace AOP\Advisor;

/**
 * Implemented by aspect objects that decide their own precedence (Spring's `Ordered`). It takes precedence over
 * `#[Order]`; lower values run first on the way in (outermost).
 */
interface Ordered
{
    public function getOrder(): int;
}
