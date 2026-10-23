<?php

declare(strict_types=1);

namespace AOP\Interceptor;

use AOP\JoinPoint\MethodInvocation;
use AOP\Pointcut\PointcutInterface;
use AOP\Pointcut\RuntimeMatchContext;
use AOP\Pointcut\StaticMatchContext;

/**
 * Wraps an advice interceptor whose pointcut has a runtime part: skips it when the call does not match,
 * otherwise hands it the values bound by the pointcut.
 */
final class RuntimeGuardInterceptor implements MethodInterceptorInterface
{
    public function __construct(
        private readonly PointcutInterface $pointcut,
        private readonly MethodInterceptorInterface $inner,
        private readonly StaticMatchContext $static,
    ) {
    }

    public function invoke(MethodInvocation $invocation): mixed
    {
        $context = new RuntimeMatchContext(
            $invocation->getThis(),
            $invocation->getTarget(),
            $invocation->getSignature()->method,
            $invocation->getArgs(),
            $this->static,
        );

        if (!$this->pointcut->matchesRuntime($context)) {
            return $invocation->proceed();
        }

        return $this->inner->invoke($invocation->withBoundArguments($context->bound()));
    }
}
