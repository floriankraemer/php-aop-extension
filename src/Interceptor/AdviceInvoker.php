<?php

declare(strict_types=1);

namespace AOP\Interceptor;

use AOP\Aspect\AspectInstanceResolverInterface;
use AOP\Aspect\AspectScope;
use AOP\Binding\BindingPlan;
use AOP\JoinPoint\MethodInvocation;
use AOP\Runtime\AdviceExecutionWrapper;

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
            // The resolver derives the instance from the control flow or the enclosing type of the join point.
            AspectScope::PER_CFLOW, AspectScope::PER_CFLOW_BELOW, AspectScope::PER_TYPE_WITHIN => $this->aspects->get($this->aspectClass, $invocation),
        };
        $arguments = $this->plan->resolve($invocation, $invocation->getBoundArguments(), $returnValue, $thrown);

        $method = $this->method;
        $call = static fn (array $values): mixed => $aspect->{$method}(...$values);

        // A runtime that supports `adviceexecution()` wraps the call in the advice-execution join point.
        return $this->aspects instanceof AdviceExecutionWrapper
            ? $this->aspects->wrapAdvice($invocation, $this->aspectClass, $aspect, $method, $arguments->values, $call)
            : $call($arguments->values);
    }
}
