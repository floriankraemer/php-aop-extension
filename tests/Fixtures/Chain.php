<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures;

use AOP\Advice\AdviceDefinition;
use AOP\Advice\AdviceType;
use AOP\Aspect\ArrayAspectInstanceResolver;
use AOP\Binding\BindingPlan;
use AOP\Binding\ParameterBinder;
use AOP\Interceptor\MethodInterceptorInterface;
use AOP\JoinPoint\MethodInvocation;
use AOP\Pointcut\Expression\PointcutExpressionCompiler;
use AOP\Tests\Fixtures\Aspect\ProbeAspect;
use AOP\Tests\Fixtures\Service\Greeter;

/**
 * Test helpers to wire a ProbeAspect method into an interceptor and run it against Greeter::greet().
 */
final class Chain
{
    public static function plan(string $method, AdviceType $type, ?string $returning = null, ?string $throwing = null): BindingPlan
    {
        $advice = new AdviceDefinition(ProbeAspect::class, $method, $type, 'execution(* *::*(..))', $returning, $throwing, 0);
        $pointcut = (new PointcutExpressionCompiler())->compile($advice->expression);

        return (new ParameterBinder())->plan(new \ReflectionMethod(ProbeAspect::class, $method), $advice, $pointcut);
    }

    /**
     * @param list<MethodInterceptorInterface>          $interceptors
     * @param \Closure(array<int|string, mixed>): mixed $target
     * @param array<int|string, mixed>                  $args
     */
    public static function invocation(array $interceptors, \Closure $target, array $args = ['World']): MethodInvocation
    {
        return Jp::invocation(new Greeter(), 'greet', $args, $interceptors, $target);
    }

    public static function resolver(ProbeAspect $probe): ArrayAspectInstanceResolver
    {
        return new ArrayAspectInstanceResolver([ProbeAspect::class => $probe]);
    }
}
