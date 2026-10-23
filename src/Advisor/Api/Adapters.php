<?php

declare(strict_types=1);

namespace AOP\Advisor\Api;

use AOP\Advice\AdviceType;
use AOP\Advice\Api\AfterReturningAdviceInterface;
use AOP\Advice\Api\Interceptor\AfterReturningAdviceBridge;
use AOP\Advice\Api\Interceptor\BeforeAdviceBridge;
use AOP\Advice\Api\Interceptor\InterceptorBridge;
use AOP\Advice\Api\Interceptor\ThrowsAdviceBridge;
use AOP\Advice\Api\MethodBeforeAdviceInterface;
use AOP\Advice\Api\MethodInterceptorInterface as ApiInterceptor;
use AOP\Advice\Api\ThrowsAdviceInterface;
use AOP\Interceptor\MethodInterceptorInterface;

/**
 * The adapters every registry starts with: one per advice interface, plus engine-level interceptors as they are.
 */
final class Adapters
{
    /**
     * @return list<AdvisorAdapterInterface>
     */
    public static function defaults(): array
    {
        return [
            new ClosureAdvisorAdapter(
                static fn (object $a): bool => $a instanceof MethodInterceptorInterface,
                static fn (object $a): MethodInterceptorInterface => $a instanceof MethodInterceptorInterface ? $a : throw new \LogicException('unreachable'),
            ),
            new ClosureAdvisorAdapter(
                static fn (object $a): bool => $a instanceof ApiInterceptor,
                static fn (object $a): MethodInterceptorInterface => new InterceptorBridge($a instanceof ApiInterceptor ? $a : throw new \LogicException('unreachable')),
            ),
            new ClosureAdvisorAdapter(
                static fn (object $a): bool => $a instanceof MethodBeforeAdviceInterface,
                static fn (object $a): MethodInterceptorInterface => new BeforeAdviceBridge($a instanceof MethodBeforeAdviceInterface ? $a : throw new \LogicException('unreachable')),
                AdviceType::BEFORE,
            ),
            new ClosureAdvisorAdapter(
                static fn (object $a): bool => $a instanceof AfterReturningAdviceInterface,
                static fn (object $a): MethodInterceptorInterface => new AfterReturningAdviceBridge($a instanceof AfterReturningAdviceInterface ? $a : throw new \LogicException('unreachable')),
                AdviceType::AFTER_RETURNING,
            ),
            new ClosureAdvisorAdapter(
                static fn (object $a): bool => $a instanceof ThrowsAdviceInterface,
                static fn (object $a): MethodInterceptorInterface => new ThrowsAdviceBridge($a instanceof ThrowsAdviceInterface ? $a : throw new \LogicException('unreachable')),
                AdviceType::AFTER_THROWING,
            ),
        ];
    }
}
