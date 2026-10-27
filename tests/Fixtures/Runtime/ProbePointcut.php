<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Runtime;

use AOP\Pointcut\ControlFlowAwareInterface;
use AOP\Pointcut\PointcutInterface;
use AOP\Pointcut\RuntimeMatchContext;
use AOP\Pointcut\StaticMatchContext;

/**
 * Decides with closures and records every static context it is asked about, so tests can assert what the runtime
 * handed to the pointcut (kind, caller, service, introduced interfaces).
 */
final class ProbePointcut implements PointcutInterface, ControlFlowAwareInterface
{
    /** @var list<StaticMatchContext> contexts seen by matchesClass() */
    public array $classContexts = [];

    /** @var list<StaticMatchContext> contexts seen by matchesMethod() */
    public array $methodContexts = [];

    /**
     * @param \Closure(StaticMatchContext): bool $method the full static decision
     * @param \Closure(StaticMatchContext): bool $class  the class-level decision
     * @param list<PointcutInterface>            $cflow  pointcuts whose join points must be tracked on the flow stack
     */
    public function __construct(
        private readonly \Closure $method,
        private readonly ?\Closure $class = null,
        private readonly bool $callerSensitive = false,
        private readonly array $cflow = [],
    ) {
    }

    public function cflowPointcuts(): array
    {
        return $this->cflow;
    }

    public function matchesClass(?\ReflectionClass $class, StaticMatchContext $ctx): bool
    {
        $this->classContexts[] = $ctx;

        return null === $this->class || ($this->class)($ctx);
    }

    public function matchesMethod(?\ReflectionFunctionAbstract $function, StaticMatchContext $ctx): bool
    {
        $this->methodContexts[] = $ctx;

        return ($this->method)($ctx);
    }

    public function isRuntime(): bool
    {
        return false;
    }

    public function matchesRuntime(RuntimeMatchContext $ctx): bool
    {
        return true;
    }

    public function bindings(): array
    {
        return [];
    }

    public function isCallerSensitive(): bool
    {
        return $this->callerSensitive;
    }
}
