<?php

declare(strict_types=1);

namespace AOP\Advisor\Api;

use AOP\Pointcut\Api\RegexpMethodPointcut;

/**
 * Advice for the methods matching regular expressions (Spring's RegexpMethodPointcutAdvisor).
 * See RegexpMethodPointcut for the pattern syntax.
 */
final class RegexpMethodPointcutAdvisor extends DefaultPointcutAdvisor
{
    /**
     * @param string|list<string> $patterns
     * @param string|list<string> $excludedPatterns
     */
    public function __construct(
        string|array $patterns,
        object $advice,
        int $order = PHP_INT_MAX,
        ?string $id = null,
        string|array $excludedPatterns = [],
        AdvisorAdapterRegistry $adapters = new AdvisorAdapterRegistry(),
    ) {
        parent::__construct(new RegexpMethodPointcut($patterns, $excludedPatterns), $advice, $order, $id, $adapters);
    }
}
