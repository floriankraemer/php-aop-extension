<?php

declare(strict_types=1);

namespace AOP\TargetSource;

use AOP\Interceptor\MethodInterceptorInterface;
use AOP\JoinPoint\MethodInvocation;
use AOP\JoinPoint\MethodSignature;

/**
 * Runs the intercepted method on a target taken from the source instead of on the intercepted object.
 *
 * @internal built by TargetSourceStandIn
 */
final readonly class TargetSourceInterceptor implements MethodInterceptorInterface
{
    public function __construct(private TargetSourceInterface $source)
    {
    }

    public function invoke(MethodInvocation $invocation): mixed
    {
        $signature = $invocation->getSignature();
        if (!$signature instanceof MethodSignature) {
            return $invocation->proceed();
        }

        $target = $this->source->getTarget();
        try {
            // virtual dispatch on the target (it may override the method); private methods are not virtual
            $method = $signature->method->isPrivate() ? $signature->method : new \ReflectionMethod($target, $signature->methodName);
            $result = $method->invokeArgs($target, $invocation->getArgs());
        } finally {
            $this->source->releaseTarget($target);
        }

        return $result === $target ? $invocation->getThis() : $result;
    }
}
