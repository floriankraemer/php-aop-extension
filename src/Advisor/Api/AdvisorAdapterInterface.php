<?php

declare(strict_types=1);

namespace AOP\Advisor\Api;

use AOP\Advice\Api\MethodInterceptorInterface as SpringMethodInterceptor;
use AOP\Interceptor\MethodInterceptorInterface as EngineMethodInterceptor;

/**
 * Teaches the AdvisorAdapterRegistry one kind of advice object (Spring's AdvisorAdapter).
 * Register one for a custom advice type ("Defining New Advice Types").
 *
 * Two interfaces are called `MethodInterceptorInterface`; do not mix them up:
 *
 * - `AOP\Interceptor\MethodInterceptorInterface` is the ENGINE-level interface: `invoke(MethodInvocation)`. This is what
 *   `getInterceptor()` is meant to return, and what the chain runs.
 * - `AOP\Advice\Api\MethodInterceptorInterface` is the Spring-style advice interface: `invoke(ProceedingJoinPointInterface)`.
 *   Returning one of those is accepted too; the registry wraps it in `InterceptorBridge`.
 *
 * Anything else raises a `LogicException` from the registry that names the adapter and the expected types.
 */
interface AdvisorAdapterInterface
{
    public function supportsAdvice(object $advice): bool;

    /**
     * The interceptor that runs the advice: an engine `AOP\Interceptor\MethodInterceptorInterface` (preferred), or a
     * Spring-style `AOP\Advice\Api\MethodInterceptorInterface`, which the registry wraps in the existing bridge.
     * Only called for advice `supportsAdvice()` accepted.
     */
    public function getInterceptor(object $advice): EngineMethodInterceptor|SpringMethodInterceptor;
}
