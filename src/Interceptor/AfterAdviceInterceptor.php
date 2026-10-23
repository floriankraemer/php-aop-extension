<?php

declare(strict_types=1);

namespace AOP\Interceptor;

use AOP\Aspect\AspectInstanceResolverInterface;
use AOP\Aspect\AspectScope;
use AOP\Binding\BindingPlan;
use AOP\JoinPoint\MethodInvocation;

final class AfterAdviceInterceptor implements MethodInterceptorInterface
{
    private readonly AdviceInvoker $advice;

    /**
     * @param class-string $aspectClass
     */
    public function __construct(AspectInstanceResolverInterface $aspects, string $aspectClass, string $method, BindingPlan $plan, AspectScope $scope = AspectScope::SINGLETON)
    {
        $this->advice = new AdviceInvoker($aspects, $aspectClass, $method, $plan, $scope);
    }

    public function invoke(MethodInvocation $invocation): mixed
    {
        try {
            return $invocation->proceed();
        } finally {
            $this->advice->call($invocation);
        }
    }
}
