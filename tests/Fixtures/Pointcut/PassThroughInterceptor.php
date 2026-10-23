<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Pointcut;

use AOP\Interceptor\MethodInterceptorInterface;
use AOP\JoinPoint\MethodInvocation;

/**
 * A programmatic interceptor that records its invocations and proceeds.
 */
final class PassThroughInterceptor implements MethodInterceptorInterface
{
    public int $calls = 0;

    public function invoke(MethodInvocation $invocation): mixed
    {
        ++$this->calls;

        return $invocation->proceed();
    }
}
