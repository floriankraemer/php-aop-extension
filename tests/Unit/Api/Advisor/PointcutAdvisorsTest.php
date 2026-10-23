<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Api\Advisor;

use AOP\Advice\AdviceType;
use AOP\Advice\Api\DelegatingIntroductionInterceptor;
use AOP\Advisor\Advisor;
use AOP\Advisor\AdvisorOrderComparator;
use AOP\Advisor\Api\DefaultIntroductionAdvisor;
use AOP\Advisor\Api\DefaultPointcutAdvisor;
use AOP\Advisor\Api\NameMatchMethodPointcutAdvisor;
use AOP\Advisor\Api\PointcutAdvisorInterface;
use AOP\Advisor\Api\RegexpMethodPointcutAdvisor;
use AOP\Advisor\Api\UnknownAdviceTypeException;
use AOP\Pointcut\Api\ClassFilterInterface;
use AOP\Pointcut\Api\ExpressionPointcut;
use AOP\Pointcut\Api\NameMatchMethodPointcut;
use AOP\Tests\Fixtures\Api\Account;
use AOP\Tests\Fixtures\Api\AuditAdvice;
use AOP\Tests\Fixtures\Api\BeforeOnly;
use AOP\Tests\Fixtures\Api\Customer;
use AOP\Tests\Fixtures\Api\Describable;
use AOP\Tests\Fixtures\Api\DescribableImpl;
use AOP\Tests\Fixtures\Api\Recorder;
use AOP\Tests\Fixtures\Api\Run;
use AOP\Tests\Fixtures\Api\SpecificThrows;
use AOP\Tests\Fixtures\Log;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PointcutAdvisorsTest extends TestCase
{
    protected function setUp(): void
    {
        Log::reset();
    }

    #[Test]
    public function aDefaultPointcutAdvisorProducesAProgrammaticAdvisor(): void
    {
        $pointcut = new NameMatchMethodPointcut('get*');
        $advice = new BeforeOnly();
        $api = new DefaultPointcutAdvisor($pointcut, $advice, 5, 'my-advisor');

        $advisor = $api->toAdvisor();

        self::assertInstanceOf(PointcutAdvisorInterface::class, $api);
        self::assertInstanceOf(Advisor::class, $advisor);
        self::assertSame('my-advisor', $advisor->id);
        self::assertSame(5, $advisor->order);
        self::assertSame($pointcut, $advisor->pointcut);
        self::assertNull($advisor->advice);
        self::assertNotNull($advisor->interceptor);
        self::assertSame(AdviceType::BEFORE, $advisor->adviceType());
        self::assertSame($advisor, $api->toAdvisor(), 'built once');
        self::assertSame($advice, $api->getAdvice());
        self::assertSame('my-advisor', $api->getId());
        self::assertSame(5, $api->getOrder());
        self::assertSame($pointcut, $api->getPointcut());
    }

    #[Test]
    public function aStringPointcutIsCompiledAsAnExpression(): void
    {
        $api = new DefaultPointcutAdvisor('execution(* AOP\Tests\Fixtures\Api\Account::get*(..))', new BeforeOnly());

        self::assertInstanceOf(ExpressionPointcut::class, $api->getPointcut());
        self::assertSame(PHP_INT_MAX, $api->getOrder());

        Run::call([$api->toAdvisor()], new Account(), 'getBalance');
        Run::call([$api->toAdvisor()], new Account(), 'deposit', [1]);
        self::assertSame(['before'], Log::$entries);
    }

    #[Test]
    public function defaultIdsAreUniqueAndNameTheAdviceClass(): void
    {
        $a = new DefaultPointcutAdvisor('execution(* **::*(..))', new BeforeOnly());
        $b = new DefaultPointcutAdvisor('execution(* **::*(..))', new BeforeOnly());

        self::assertNotSame($a->getId(), $b->getId());
        self::assertStringStartsWith(BeforeOnly::class.'#', $a->getId());
    }

    #[Test]
    public function unsupportedAdviceFailsWhenBuildingTheAdvisor(): void
    {
        $api = new DefaultPointcutAdvisor('execution(* **::*(..))', new AuditAdvice());

        $this->expectException(UnknownAdviceTypeException::class);

        $api->toAdvisor();
    }

    #[Test]
    public function regexpAdvisorMatchesByPattern(): void
    {
        $recorder = new Recorder();
        $advisor = (new RegexpMethodPointcutAdvisor('.*::(get|set).*', $recorder, 1, 'regexp', '.*::setBalance'))->toAdvisor();

        Run::call([$advisor], new Account(), 'getBalance');
        Run::call([$advisor], new Account(), 'setBalance', [1]);
        Run::call([$advisor], new Account(), 'deposit', [1]);

        self::assertSame(['r:in', 'r:out'], $recorder->events);
    }

    #[Test]
    public function nameMatchAdvisorMatchesByName(): void
    {
        $advice = new BeforeOnly('nm');
        $advisor = (new NameMatchMethodPointcutAdvisor(['get*', 'deposit'], $advice))->toAdvisor();

        Run::call([$advisor], new Account(), 'getBalance');
        Run::call([$advisor], new Account(), 'deposit', [1]);
        Run::call([$advisor], new Account(), 'setBalance', [1]);

        self::assertSame(['nm', 'nm', 'set:1'], Log::$entries);
    }

    #[Test]
    public function throwsAdviceWorksThroughAdvisors(): void
    {
        $throws = new SpecificThrows();
        $recorder = new Recorder();
        $advisors = [
            (new NameMatchMethodPointcutAdvisor('fail*', $throws, 1, 't'))->toAdvisor(),
            (new NameMatchMethodPointcutAdvisor('getBalance', $recorder, 2, 'r'))->toAdvisor(),
        ];

        try {
            Run::call($advisors, new Account(), 'fail');
            self::fail();
        } catch (\InvalidArgumentException) {
        }

        self::assertSame(['invalid-argument'], $throws->handled);
    }

    #[Test]
    public function advisorsSortByOrderThenId(): void
    {
        $advisors = [
            (new DefaultPointcutAdvisor('execution(* **::*(..))', new BeforeOnly('late'), 20, 'b'))->toAdvisor(),
            (new DefaultPointcutAdvisor('execution(* **::*(..))', new BeforeOnly('early'), 10, 'z'))->toAdvisor(),
            (new DefaultPointcutAdvisor('execution(* **::*(..))', new BeforeOnly('also-late'), 20, 'a'))->toAdvisor(),
        ];

        Run::call($advisors, new Account(), 'getBalance');

        self::assertSame(['early', 'also-late', 'late'], Log::$entries);
        usort($advisors, new AdvisorOrderComparator());
        self::assertSame(['z', 'a', 'b'], array_map(static fn (Advisor $a): string => $a->id, $advisors));
    }

    #[Test]
    public function introductionAdvisorsRouteIntroducedMethodsToTheDelegate(): void
    {
        $interceptor = new DelegatingIntroductionInterceptor(new DescribableImpl());
        $filter = new class implements ClassFilterInterface {
            public function matches(\ReflectionClass $class): bool
            {
                return Customer::class === $class->getName();
            }
        };
        $api = new DefaultIntroductionAdvisor($interceptor, [Describable::class], $filter, 3, 'intro');
        $advisor = $api->toAdvisor();

        self::assertSame([Describable::class], $api->introducedInterfaces());
        self::assertSame('intro', $advisor->id);
        self::assertSame(3, $advisor->order);
        self::assertSame('described', Run::call([$advisor], new Customer(), 'describe'));
        self::assertSame('name', Run::call([$advisor], new Customer(), 'name'));
        self::assertSame(100, Run::call([$advisor], new Account(), 'getBalance'));
    }

    #[Test]
    public function introductionAdvisorsRejectInterfacesTheInterceptorLacks(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DefaultIntroductionAdvisor(new DelegatingIntroductionInterceptor(new DescribableImpl()), [\Countable::class]);
    }
}
