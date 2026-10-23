<?php

declare(strict_types=1);

namespace AOP\Advice\Api\Interceptor;

use AOP\Advice\Api\AfterReturningAdviceInterface;
use AOP\Interceptor\MethodInterceptorInterface;
use AOP\JoinPoint\MethodInvocation;

final readonly class AfterReturningAdviceBridge implements MethodInterceptorInterface
{
    public function __construct(private AfterReturningAdviceInterface $advice)
    {
    }

    public function invoke(MethodInvocation $invocation): mixed
    {
        $result = $invocation->proceed();
        $this->advice->afterReturning($result, $invocation);

        return $result;
    }
}
