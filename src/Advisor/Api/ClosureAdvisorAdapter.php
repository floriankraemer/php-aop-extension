<?php

declare(strict_types=1);

namespace AOP\Advisor\Api;

use AOP\Advice\AdviceType;
use AOP\Advice\Api\MethodInterceptorInterface as SpringMethodInterceptor;
use AOP\Interceptor\MethodInterceptorInterface;

/**
 * An advisor adapter written as two closures, for custom advice types that do not deserve a class.
 */
final readonly class ClosureAdvisorAdapter implements TypedAdvisorAdapterInterface
{
    /**
     * @param \Closure(object): bool                                                 $supports
     * @param \Closure(object): (MethodInterceptorInterface|SpringMethodInterceptor) $interceptor engine-level interceptor (preferred), or a Spring-style one that the registry wraps
     */
    public function __construct(
        private \Closure $supports,
        private \Closure $interceptor,
        private AdviceType $type = AdviceType::AROUND,
    ) {
    }

    public function supportsAdvice(object $advice): bool
    {
        return ($this->supports)($advice);
    }

    public function getInterceptor(object $advice): MethodInterceptorInterface|SpringMethodInterceptor
    {
        return ($this->interceptor)($advice);
    }

    public function adviceType(object $advice): AdviceType
    {
        return $this->type;
    }
}
