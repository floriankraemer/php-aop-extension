<?php

declare(strict_types=1);

namespace AOP\Pointcut\Api;

use AOP\JoinPoint\FunctionSignature;
use AOP\JoinPoint\JoinPointData;
use AOP\JoinPoint\JoinPointKind;
use AOP\JoinPoint\MethodInvocation;
use AOP\JoinPoint\MethodSignature;
use AOP\JoinPoint\SignatureInterface;
use AOP\JoinPoint\StaticPart;
use AOP\Pointcut\AndPointcut;
use AOP\Pointcut\ControlFlowInterface;
use AOP\Pointcut\EmptyControlFlow;
use AOP\Pointcut\OrPointcut;
use AOP\Pointcut\PointcutInterface;
use AOP\Pointcut\RuntimeMatchContext;
use AOP\Pointcut\StaticMatchContext;

/**
 * Convenience operations on pointcuts (Spring's Pointcuts).
 */
final class Pointcuts
{
    private function __construct()
    {
    }

    public static function union(PointcutInterface $a, PointcutInterface $b): PointcutInterface
    {
        return new OrPointcut($a, $b);
    }

    public static function intersection(PointcutInterface $a, PointcutInterface $b): PointcutInterface
    {
        return new AndPointcut($a, $b);
    }

    /**
     * Would the pointcut advise a method execution on this class with these arguments?
     *
     * Static part first, then (for runtime pointcuts) the runtime part against a synthetic invocation.
     *
     * @param \ReflectionClass<object>|null $class  the runtime class; null for functions
     * @param array<int|string, mixed>      $args
     * @param ?object                       $target the object, only needed by pointcuts that look at it
     */
    public static function matches(
        PointcutInterface $pointcut,
        \ReflectionFunctionAbstract $function,
        ?\ReflectionClass $class = null,
        array $args = [],
        ?object $target = null,
        ControlFlowInterface $controlFlow = new EmptyControlFlow(),
    ): bool {
        $class ??= $function instanceof \ReflectionMethod ? $function->getDeclaringClass() : null;
        $kind = $function instanceof \ReflectionMethod ? JoinPointKind::METHOD_EXECUTION : JoinPointKind::FUNCTION_EXECUTION;
        $static = new StaticMatchContext($kind, $class, $function);

        if (!$pointcut->matchesClass($class, $static) || !$pointcut->matchesMethod($function, $static)) {
            return false;
        }
        if (!$pointcut->isRuntime()) {
            return true;
        }

        $signature = $function instanceof \ReflectionMethod
            ? MethodSignature::fromReflection($function)
            : new FunctionSignature($function->getName(), $function instanceof \ReflectionFunction ? $function : null);
        $invocation = new MethodInvocation(
            self::data($kind, $signature, $function instanceof \ReflectionMethod && $function->isStatic() ? null : $target),
            $args,
            [],
            static fn (array $a): null => null,
        );

        return $pointcut->matchesRuntime(new RuntimeMatchContext($invocation, $static, $controlFlow));
    }

    private static function data(JoinPointKind $kind, SignatureInterface $signature, ?object $target): JoinPointData
    {
        return new JoinPointData($kind, $target, $target, $signature, new StaticPart($kind, $signature));
    }
}
