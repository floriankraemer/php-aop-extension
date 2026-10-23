<?php

declare(strict_types=1);

namespace AOP\Advisor\Api;

use AOP\Interceptor\MethodInterceptorInterface;

/**
 * Teaches the AdvisorAdapterRegistry one kind of advice object (Spring's AdvisorAdapter).
 * Register one for a custom advice type ("Defining New Advice Types").
 */
interface AdvisorAdapterInterface
{
    public function supportsAdvice(object $advice): bool;

    /**
     * The engine-level interceptor that runs the advice. Only called for advice `supportsAdvice()` accepted.
     */
    public function getInterceptor(object $advice): MethodInterceptorInterface;
}
