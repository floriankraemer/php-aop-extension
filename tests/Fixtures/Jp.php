<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures;

use AOP\Interceptor\MethodInterceptorInterface;
use AOP\JoinPoint\JoinPointData;
use AOP\JoinPoint\JoinPointKind;
use AOP\JoinPoint\MethodInvocation;
use AOP\JoinPoint\MethodSignature;
use AOP\JoinPoint\StaticPart;
use AOP\Pointcut\EnclosingContext;
use AOP\Pointcut\RuntimeMatchContext;
use AOP\Pointcut\StaticMatchContext;

/**
 * Test helpers building static contexts, join point data, invocations and runtime contexts for method join points.
 */
final class Jp
{
    /**
     * @param class-string       $class
     * @param list<class-string> $introduced
     */
    public static function ctx(
        string $class,
        ?string $method = null,
        JoinPointKind $kind = JoinPointKind::METHOD_EXECUTION,
        ?string $service = null,
        array $introduced = [],
        ?EnclosingContext $caller = null,
    ): StaticMatchContext {
        return new StaticMatchContext(
            $kind,
            new \ReflectionClass($class),
            null === $method ? null : new \ReflectionMethod($class, $method),
            null,
            null,
            $caller,
            $service,
            $introduced,
        );
    }

    /**
     * @param class-string $class
     */
    public static function caller(string $class, ?string $method = null): EnclosingContext
    {
        return new EnclosingContext(new \ReflectionClass($class), null === $method ? null : new \ReflectionMethod($class, $method));
    }

    public static function data(object $target, string $method, JoinPointKind $kind = JoinPointKind::METHOD_EXECUTION, ?object $thisObject = null, bool $outermost = true): JoinPointData
    {
        $signature = MethodSignature::fromReflection(new \ReflectionMethod($target, $method));
        $reflection = $signature->method;
        $isStatic = $reflection->isStatic();

        return new JoinPointData(
            $kind,
            $isStatic ? null : ($thisObject ?? $target),
            $isStatic ? null : $target,
            $signature,
            new StaticPart($kind, $signature),
            outermost: $outermost,
        );
    }

    /**
     * @param array<int|string, mixed>         $args
     * @param list<MethodInterceptorInterface> $interceptors
     */
    public static function invocation(object $target, string $method, array $args = [], array $interceptors = [], ?\Closure $invoker = null, JoinPointKind $kind = JoinPointKind::METHOD_EXECUTION): MethodInvocation
    {
        return new MethodInvocation(self::data($target, $method, $kind), $args, $interceptors, $invoker ?? static fn (array $a): null => null);
    }

    /**
     * @param array<int|string, mixed> $args
     */
    public static function runtime(object $target, string $method, array $args = [], ?StaticMatchContext $static = null): RuntimeMatchContext
    {
        return new RuntimeMatchContext(self::invocation($target, $method, $args), $static ?? self::ctx($target::class, $method));
    }
}
