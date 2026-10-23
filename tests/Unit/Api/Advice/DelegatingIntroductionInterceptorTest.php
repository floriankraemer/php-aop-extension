<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Api\Advice;

use AOP\Advice\Api\DelegatingIntroductionInterceptor;
use AOP\Advice\Api\Interceptor\InterceptorBridge;
use AOP\Advice\Api\IntroductionInterceptorInterface;
use AOP\Tests\Fixtures\Api\Customer;
use AOP\Tests\Fixtures\Api\Describable;
use AOP\Tests\Fixtures\Api\DescribableImpl;
use AOP\Tests\Fixtures\Jp;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DelegatingIntroductionInterceptorTest extends TestCase
{
    #[Test]
    public function introducesTheInterfacesOfTheDelegate(): void
    {
        $interceptor = new DelegatingIntroductionInterceptor(new DescribableImpl());

        self::assertInstanceOf(IntroductionInterceptorInterface::class, $interceptor);
        self::assertTrue($interceptor->implementsInterface(Describable::class));
        self::assertTrue($interceptor->implementsInterface('\\'.Describable::class));
        self::assertFalse($interceptor->implementsInterface(\Countable::class));
        self::assertSame([Describable::class], $interceptor->introducedInterfaces());
    }

    #[Test]
    public function callsOfIntroducedMethodsRunOnTheDelegate(): void
    {
        $delegate = new DescribableImpl();
        $interceptor = new DelegatingIntroductionInterceptor($delegate);
        $customer = new Customer();
        $targetCalled = false;

        $result = Jp::invocation($customer, 'describe', ['> '], [new InterceptorBridge($interceptor)], function () use (&$targetCalled): string {
            $targetCalled = true;

            return 'target';
        })->proceed();

        self::assertSame('> described', $result);
        self::assertFalse($targetCalled);
    }

    #[Test]
    public function otherMethodsProceedLikeAPlainAroundInterceptor(): void
    {
        $interceptor = new DelegatingIntroductionInterceptor(new DescribableImpl());

        $result = Jp::invocation(new Customer(), 'name', [], [new InterceptorBridge($interceptor)], static fn (array $a): string => 'target')->proceed();

        self::assertSame('target', $result);
    }

    #[Test]
    public function aDelegateReturningItselfYieldsTheTarget(): void
    {
        $interceptor = new DelegatingIntroductionInterceptor(new DescribableImpl());
        $customer = new Customer();

        $result = Jp::invocation($customer, 'self', [], [new InterceptorBridge($interceptor)])->proceed();

        self::assertSame($customer, $result);
    }

    #[Test]
    public function onlyTheGivenInterfacesAreIntroduced(): void
    {
        $interceptor = new DelegatingIntroductionInterceptor(new DescribableImpl(), []);

        $result = Jp::invocation(new Customer(), 'describe', [], [new InterceptorBridge($interceptor)], static fn (array $a): string => 'target')->proceed();

        self::assertSame('target', $result);
        self::assertSame([], $interceptor->introducedInterfaces());
    }

    #[Test]
    public function theInterfacesMustBeImplementedByTheDelegate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not implement the interface Countable');

        new DelegatingIntroductionInterceptor(new DescribableImpl(), [\Countable::class]);
    }

    #[Test]
    public function exposesTheDelegate(): void
    {
        $delegate = new DescribableImpl();

        self::assertSame($delegate, (new DelegatingIntroductionInterceptor($delegate))->getDelegate());
    }
}
