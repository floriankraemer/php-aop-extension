<?php

declare(strict_types=1);

namespace AOP\Advice\Api;

use AOP\JoinPoint\ProceedingJoinPointInterface;

/**
 * Around advice, Spring's `MethodInterceptor`: decides whether, when and with which arguments the call proceeds.
 *
 * Not to be confused with `AOP\Interceptor\MethodInterceptorInterface`, the engine-level interface that
 * takes a `MethodInvocation`; `InterceptorBridge` turns this one into that one (the advisor adapters do it for you).
 */
interface MethodInterceptorInterface extends AdviceInterface
{
    public function invoke(ProceedingJoinPointInterface $invocation): mixed;
}
