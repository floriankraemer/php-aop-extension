<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Api;

use AOP\Advisor\Advisor;
use AOP\Advisor\AdvisorChainFactory;
use AOP\Advisor\AdvisorRegistry;
use AOP\Aspect\ArrayAspectInstanceResolver;
use AOP\Aspect\AspectInstanceResolverInterface;
use AOP\Binding\ParameterBinder;
use AOP\JoinPoint\JoinPointKind;
use AOP\JoinPoint\MethodInvocation;
use AOP\Pointcut\ControlFlowInterface;
use AOP\Pointcut\EmptyControlFlow;
use AOP\Tests\Fixtures\Jp;

/**
 * Drives advisors the way the runtime does: AdvisorChainFactory, then a MethodInvocation around the real method.
 */
final class Run
{
    /**
     * @param list<Advisor>            $advisors
     * @param array<int|string, mixed> $args
     */
    public static function call(
        array $advisors,
        object $target,
        string $method,
        array $args = [],
        ?AspectInstanceResolverInterface $aspects = null,
        ControlFlowInterface $controlFlow = new EmptyControlFlow(),
        JoinPointKind $kind = JoinPointKind::METHOD_EXECUTION,
    ): mixed {
        return self::invocation($advisors, $target, $method, $args, $aspects, $controlFlow, $kind)->proceed();
    }

    /**
     * @param list<Advisor>            $advisors
     * @param array<int|string, mixed> $args
     */
    public static function invocation(
        array $advisors,
        object $target,
        string $method,
        array $args = [],
        ?AspectInstanceResolverInterface $aspects = null,
        ControlFlowInterface $controlFlow = new EmptyControlFlow(),
        JoinPointKind $kind = JoinPointKind::METHOD_EXECUTION,
        ?\Closure $invoker = null,
    ): MethodInvocation {
        $factory = new AdvisorChainFactory(new AdvisorRegistry($advisors), $aspects ?? new ArrayAspectInstanceResolver([]), new ParameterBinder());
        $class = new \ReflectionClass($target);
        $reflection = new \ReflectionMethod($target, $method);
        $static = Jp::ctx($target::class, $method, $kind);
        $chain = $factory->chain($class, $reflection, $static, $controlFlow);

        return new MethodInvocation(
            Jp::data($target, $method, $kind),
            $args,
            $chain,
            $invoker ?? static fn (array $a): mixed => $reflection->invokeArgs($target, $a),
        );
    }
}
