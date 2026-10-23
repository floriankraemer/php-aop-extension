<?php

declare(strict_types=1);

namespace AOP\Runtime;

use AOP\Interceptor\MethodInterceptorInterface;
use AOP\JoinPoint\MethodInvocation;

/**
 * First interceptor of every dispatched chain: puts the join point on the control flow stack and in AopContext
 * (and starts the control flows of percflow aspects) for as long as the chain runs.
 *
 * @internal
 */
final class FrameInterceptor implements MethodInterceptorInterface
{
    /**
     * @param list<class-string> $flowAspects percflow/percflowbelow aspects whose control flow starts at this join point
     * @param bool               $counts      false for advice-execution join points
     */
    public function __construct(
        private readonly ControlFlowStack $flow,
        private readonly array $flowAspects = [],
        private readonly bool $counts = true,
    ) {
    }

    public function invoke(MethodInvocation $invocation): mixed
    {
        $this->flow->enter($invocation, $this->counts);
        AopContext::push($invocation);
        foreach ($this->flowAspects as $aspect) {
            $this->flow->pushToken($aspect);
        }
        try {
            return $invocation->proceed();
        } finally {
            foreach (array_reverse($this->flowAspects) as $aspect) {
                $this->flow->popToken($aspect);
            }
            AopContext::pop();
            $this->flow->leave($this->counts);
        }
    }
}
