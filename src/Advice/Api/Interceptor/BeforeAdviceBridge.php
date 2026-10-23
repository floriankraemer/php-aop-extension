<?php

declare(strict_types=1);

namespace AOP\Advice\Api\Interceptor;

use AOP\Advice\Api\MethodBeforeAdviceInterface;
use AOP\Interceptor\MethodInterceptorInterface;
use AOP\JoinPoint\MethodInvocation;

final readonly class BeforeAdviceBridge implements MethodInterceptorInterface
{
    public function __construct(private MethodBeforeAdviceInterface $advice)
    {
    }

    public function invoke(MethodInvocation $invocation): mixed
    {
        $this->advice->before($invocation);

        return $invocation->proceed();
    }
}
