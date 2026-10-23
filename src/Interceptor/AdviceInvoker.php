<?php

declare(strict_types=1);

namespace AOP\Interceptor;

use AOP\Aspect\AspectInstanceResolverInterface;
use AOP\Aspect\AspectScope;
use AOP\Binding\BindingPlan;
use AOP\JoinPoint\MethodInvocation;

/**
 * Calls one advice method: resolves the aspect instance lazily (once) and binds the arguments.
 */
final class AdviceInvoker
{
    private ?object $singleton = null;

    /**
     * @param class-string $aspectClass
     */
    public function __construct(
        private readonly AspectInstanceResolverInterface $aspects,
        private readonly string $aspectClass,
        private readonly string $method,
        public readonly BindingPlan $plan,
        private readonly AspectScope $scope = AspectScope::SINGLETON,
    ) {
    }

    public function call(MethodInvocation $invocation, mixed $returnValue = null, ?\Throwable $thrown = null): mixed
    {
        $aspect = match ($this->scope) {
            AspectScope::SINGLETON => $this->singleton ??= $this->aspects->get($this->aspectClass),
            AspectScope::PER_TARGET => $this->aspects->get($this->aspectClass, $invocation->getTarget()),
            AspectScope::PER_THIS => $this->aspects->get($this->aspectClass, $invocation->getThis()),
        };
        $arguments = $this->plan->resolve($invocation, $invocation->getBoundArguments(), $returnValue, $thrown);

        return $aspect->{$this->method}(...$arguments->values);
    }
}
