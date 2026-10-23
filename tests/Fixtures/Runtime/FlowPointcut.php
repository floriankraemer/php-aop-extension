<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Runtime;

use AOP\JoinPoint\JoinPointKind;
use AOP\Pointcut\PointcutInterface;
use AOP\Pointcut\RuntimeMatchContext;
use AOP\Pointcut\StaticMatchContext;
use AOP\Runtime\ControlFlowConsumer;

/**
 * A minimal `cflow(execution(* *::name(..)))`: true while a method of that name is on the control flow stack.
 */
final class FlowPointcut implements PointcutInterface, ControlFlowConsumer
{
    /** @var list<list<string>> the stacks seen, as short strings */
    public array $stacks = [];

    public function __construct(private readonly string $name)
    {
    }

    public function controlFlowPointcut(): PointcutInterface
    {
        return new KindPointcut([JoinPointKind::METHOD_EXECUTION], $this->name);
    }

    public function matchesClass(?\ReflectionClass $class, StaticMatchContext $ctx): bool
    {
        return true;
    }

    public function matchesMethod(?\ReflectionFunctionAbstract $function, StaticMatchContext $ctx): bool
    {
        return true;
    }

    public function isRuntime(): bool
    {
        return true;
    }

    public function matchesRuntime(RuntimeMatchContext $ctx): bool
    {
        $stack = $ctx->controlFlow->stack();
        $this->stacks[] = array_map(static fn ($jp): string => $jp->toShortString(), $stack);
        foreach ($stack as $joinPoint) {
            if ($joinPoint->getSignature()->getName() === $this->name) {
                return true;
            }
        }

        return false;
    }

    public function bindings(): array
    {
        return [];
    }

    public function isCallerSensitive(): bool
    {
        return false;
    }
}
