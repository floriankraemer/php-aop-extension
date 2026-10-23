<?php

declare(strict_types=1);

namespace AOP\Interceptor;

use AOP\JoinPoint\MethodInvocation;

interface MethodInterceptorInterface
{
    public function invoke(MethodInvocation $invocation): mixed;
}
