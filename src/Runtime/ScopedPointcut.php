<?php

declare(strict_types=1);

namespace AOP\Runtime;

use AOP\Aspect\AspectScope;
use AOP\Pointcut\PointcutInterface;
use AOP\Pointcut\RuntimeMatchContext;
use AOP\Pointcut\StaticMatchContext;

/**
 * Applies the instantiation model of an aspect to the pointcut of one of its advisors:
 * perthis/pertarget/pertypewithin restrict the advised join points statically; percflow/percflowbelow make the
 * advice conditional on being inside the control flow the aspect is bound to (a runtime check).
 *
 * @internal
 */
final class ScopedPointcut implements PointcutInterface
{
    /**
     * @param class-string $aspectClass
     */
    public function __construct(
        private readonly PointcutInterface $inner,
        private readonly string $aspectClass,
        private readonly AspectScope $scope,
        private readonly ?PointcutInterface $restriction,
        private readonly ControlFlowStack $flow,
    ) {
    }

    public function inner(): PointcutInterface
    {
        return $this->inner;
    }

    public function matchesClass(?\ReflectionClass $class, StaticMatchContext $ctx): bool
    {
        return (!$this->restricts() || $this->restriction?->matchesClass($class, $ctx)) && $this->inner->matchesClass($class, $ctx);
    }

    public function matchesMethod(?\ReflectionFunctionAbstract $function, StaticMatchContext $ctx): bool
    {
        return (!$this->restricts() || $this->restriction?->matchesMethod($function, $ctx)) && $this->inner->matchesMethod($function, $ctx);
    }

    public function isRuntime(): bool
    {
        return $this->inFlow() || $this->inner->isRuntime();
    }

    public function matchesRuntime(RuntimeMatchContext $ctx): bool
    {
        if ($this->inFlow() && null === $this->flow->activeToken($this->aspectClass, AspectScope::PER_CFLOW_BELOW === $this->scope)) {
            return false;
        }

        return !$this->inner->isRuntime() || $this->inner->matchesRuntime($ctx);
    }

    public function bindings(): array
    {
        return $this->inner->bindings();
    }

    public function isCallerSensitive(): bool
    {
        return $this->inner->isCallerSensitive() || ($this->restricts() && ($this->restriction?->isCallerSensitive() ?? false));
    }

    private function restricts(): bool
    {
        return null !== $this->restriction && !$this->inFlow();
    }

    private function inFlow(): bool
    {
        return AspectScope::PER_CFLOW === $this->scope || AspectScope::PER_CFLOW_BELOW === $this->scope;
    }
}
