<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Pointcut;

use AOP\JoinPoint\JoinPointInterface;
use AOP\JoinPoint\JoinPointKind;
use AOP\Pointcut\ControlFlowInterface;
use AOP\Pointcut\EmptyControlFlow;
use AOP\Pointcut\EnclosingContext;
use AOP\Pointcut\Expression\NamedPointcutResolverInterface;
use AOP\Pointcut\Expression\PointcutExpressionCompiler;
use AOP\Pointcut\PointcutInterface;
use AOP\Pointcut\RuntimeMatchContext;
use AOP\Pointcut\StaticMatchContext;

/**
 * Builds the static shadows (and runtime contexts) of every join point kind for the designator tests.
 */
final class Shadow
{
    /**
     * @param array<int|string, string|null> $names
     */
    public static function compile(string $expression, array $names = [], string $aspect = '', ?NamedPointcutResolverInterface $resolver = null): PointcutInterface
    {
        return (new PointcutExpressionCompiler())->compile($expression, $names, $aspect, $resolver);
    }

    /**
     * The full static decision, the way the weaver takes it.
     */
    public static function matches(PointcutInterface $pointcut, StaticMatchContext $ctx): bool
    {
        return $pointcut->matchesClass($ctx->targetClass, $ctx) && $pointcut->matchesMethod($ctx->function, $ctx);
    }

    /**
     * @param class-string $class
     */
    public static function method(string $class, string $method, JoinPointKind $kind = JoinPointKind::METHOD_EXECUTION, ?EnclosingContext $caller = null): StaticMatchContext
    {
        return new StaticMatchContext($kind, new \ReflectionClass($class), new \ReflectionMethod($class, $method), caller: $caller);
    }

    /**
     * A constructor execution or call; the function is the class' constructor.
     *
     * @param class-string $class
     */
    public static function constructor(string $class, JoinPointKind $kind = JoinPointKind::CONSTRUCTOR_EXECUTION, ?EnclosingContext $caller = null): StaticMatchContext
    {
        $reflection = new \ReflectionClass($class);

        return new StaticMatchContext($kind, $reflection, $reflection->getConstructor(), caller: $caller);
    }

    /**
     * A constructor of $declaring executing for an object of the runtime class $class (a `parent::__construct()` call).
     *
     * @param class-string $class
     * @param class-string $declaring
     */
    public static function inheritedConstructor(string $class, string $declaring): StaticMatchContext
    {
        return new StaticMatchContext(JoinPointKind::CONSTRUCTOR_EXECUTION, new \ReflectionClass($class), new \ReflectionMethod($declaring, '__construct'));
    }

    /**
     * @param class-string $class
     */
    public static function initialization(string $class): StaticMatchContext
    {
        return new StaticMatchContext(JoinPointKind::INITIALIZATION, new \ReflectionClass($class));
    }

    /**
     * @param callable-string $function
     */
    public static function function(string $function, JoinPointKind $kind = JoinPointKind::FUNCTION_EXECUTION, ?EnclosingContext $caller = null): StaticMatchContext
    {
        return new StaticMatchContext($kind, null, new \ReflectionFunction($function), caller: $caller);
    }

    /**
     * @param class-string      $class
     * @param class-string|null $runtimeClass
     */
    public static function field(string $class, string $property, JoinPointKind $kind = JoinPointKind::FIELD_GET, ?EnclosingContext $caller = null, ?string $runtimeClass = null): StaticMatchContext
    {
        return new StaticMatchContext($kind, new \ReflectionClass($runtimeClass ?? $class), null, new \ReflectionProperty($class, $property), caller: $caller);
    }

    /**
     * @param class-string $caught
     */
    public static function handler(string $caught, ?EnclosingContext $caller = null): StaticMatchContext
    {
        return new StaticMatchContext(JoinPointKind::EXCEPTION_HANDLER, null, caughtType: new \ReflectionClass($caught), caller: $caller);
    }

    /**
     * @param class-string $aspect
     */
    public static function advice(string $aspect, string $method): StaticMatchContext
    {
        return self::method($aspect, $method, JoinPointKind::ADVICE_EXECUTION);
    }

    /**
     * @param class-string $class
     */
    public static function caller(string $class, ?string $method = null): EnclosingContext
    {
        return new EnclosingContext(new \ReflectionClass($class), null === $method ? null : new \ReflectionMethod($class, $method));
    }

    /**
     * @param callable-string $function
     */
    public static function functionCaller(string $function): EnclosingContext
    {
        return new EnclosingContext(null, new \ReflectionFunction($function));
    }

    public static function runtime(JoinPointInterface $jp, StaticMatchContext $static, ?ControlFlowInterface $flow = null): RuntimeMatchContext
    {
        return new RuntimeMatchContext($jp, $static, $flow ?? new EmptyControlFlow());
    }
}
