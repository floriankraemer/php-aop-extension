<?php

declare(strict_types=1);

namespace AOP\Advisor\Api;

use AOP\Pointcut\Api\NameMatchMethodPointcut;

/**
 * Advice for the methods with given names, `*` allowed (Spring's NameMatchMethodPointcutAdvisor).
 */
final class NameMatchMethodPointcutAdvisor extends DefaultPointcutAdvisor
{
    /**
     * @param string|list<string> $mappedNames
     */
    public function __construct(
        string|array $mappedNames,
        object $advice,
        int $order = PHP_INT_MAX,
        ?string $id = null,
        AdvisorAdapterRegistry $adapters = new AdvisorAdapterRegistry(),
    ) {
        parent::__construct(new NameMatchMethodPointcut($mappedNames), $advice, $order, $id, $adapters);
    }
}
