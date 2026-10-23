<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Api\Advice;

use AOP\Advice\Api\Interceptor\AfterReturningAdviceBridge;
use AOP\Advice\Api\Interceptor\BeforeAdviceBridge;
use AOP\Advice\Api\Interceptor\InterceptorBridge;
use AOP\JoinPoint\ProceedingJoinPointInterface;
use AOP\Tests\Fixtures\Api\Account;
use AOP\Tests\Fixtures\Api\Recorder;
use AOP\Tests\Fixtures\Jp;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AdviceBridgesTest extends TestCase
{
    #[Test]
    public function beforeAdviceRunsBeforeTheTarget(): void
    {
        $recorder = new Recorder();
        $account = new Account();
        $target = function (array $a) use ($recorder): int {
            $recorder->events[] = 'target';

            return 7;
        };

        $result = Jp::invocation($account, 'getBalance', [], [new BeforeAdviceBridge($recorder)], $target)->proceed();

        self::assertSame(7, $result);
        self::assertSame(['r:before:getBalance', 'target'], $recorder->events);
    }

    #[Test]
    public function aThrowingBeforeAdvicePreventsTheCall(): void
    {
        $advice = new class implements \AOP\Advice\Api\MethodBeforeAdviceInterface {
            public function before(\AOP\JoinPoint\JoinPointInterface $joinPoint): void
            {
                throw new \DomainException('denied');
            }
        };
        $called = false;

        try {
            Jp::invocation(new Account(), 'getBalance', [], [new BeforeAdviceBridge($advice)], function () use (&$called): int {
                $called = true;

                return 1;
            })->proceed();
            self::fail();
        } catch (\DomainException) {
        }

        self::assertFalse($called);
    }

    #[Test]
    public function afterReturningAdviceSeesTheResultAndCannotChangeIt(): void
    {
        $recorder = new Recorder();

        $result = Jp::invocation(new Account(), 'getBalance', [], [new AfterReturningAdviceBridge($recorder)], static fn (array $a): int => 42)->proceed();

        self::assertSame(42, $result);
        self::assertSame(['r:returned:42'], $recorder->events);
    }

    #[Test]
    public function afterReturningAdviceIsSkippedWhenTheTargetThrows(): void
    {
        $recorder = new Recorder();

        try {
            Jp::invocation(new Account(), 'fail', [], [new AfterReturningAdviceBridge($recorder)], static fn (array $a): never => throw new \LogicException())->proceed();
            self::fail();
        } catch (\LogicException) {
        }

        self::assertSame([], $recorder->events);
    }

    #[Test]
    public function apiInterceptorsRunInTheEngineChain(): void
    {
        $recorder = new Recorder('i');
        $interceptor = new class implements \AOP\Advice\Api\MethodInterceptorInterface {
            public function invoke(ProceedingJoinPointInterface $invocation): mixed
            {
                return $invocation->proceed(['changed']);
            }
        };

        $bridge = new InterceptorBridge($interceptor);
        $result = Jp::invocation(new Account(), 'deposit', ['original'], [new InterceptorBridge($recorder), $bridge], static fn (array $a): array => $a)->proceed();

        self::assertSame(['changed'], $result);
        self::assertSame(['i:in', 'i:out'], $recorder->events);
        self::assertSame($interceptor, $bridge->getInterceptor());
    }
}
