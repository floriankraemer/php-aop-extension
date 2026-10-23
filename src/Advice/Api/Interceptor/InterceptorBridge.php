<?php

declare(strict_types=1);

namespace AOP\Advice\Api\Interceptor;

use AOP\Advice\Api\MethodInterceptorInterface as ApiInterceptor;
use AOP\Interceptor\MethodInterceptorInterface;
use AOP\JoinPoint\MethodInvocation;

/**
 * Lets an API-style around interceptor run in the engine's chain.
 */
final readonly class InterceptorBridge implements MethodInterceptorInterface
{
    public function __construct(private ApiInterceptor $interceptor)
    {
    }

    public function getInterceptor(): ApiInterceptor
    {
        return $this->interceptor;
    }

    public function invoke(MethodInvocation $invocation): mixed
    {
        return $this->interceptor->invoke($invocation);
    }
}
