<?php

declare(strict_types=1);

namespace AOP\Advisor\Api;

use AOP\Advice\AdviceType;

/**
 * An adapter that also tells which ordering slot its advice belongs to (the tie-break among advisors of one source).
 * Adapters that do not implement it produce AROUND advisors.
 */
interface TypedAdvisorAdapterInterface extends AdvisorAdapterInterface
{
    public function adviceType(object $advice): AdviceType;
}
