<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Api\Advisor;

use AOP\Advice\AdviceType;
use AOP\Advice\Api\Interceptor\BeforeAdviceBridge;
use AOP\Advice\Api\Interceptor\InterceptorBridge;
use AOP\Advice\Api\MethodInterceptorInterface as SpringInterceptor;
use AOP\Advisor\Api\AdvisorAdapterInterface;
use AOP\Advisor\Api\AdvisorAdapterRegistry;
use AOP\Advisor\Api\ClosureAdvisorAdapter;
use AOP\Advisor\Api\DefaultPointcutAdvisor;
use AOP\Advisor\Api\UnknownAdviceTypeException;
use AOP\Interceptor\MethodInterceptorInterface;
use AOP\JoinPoint\MethodInvocation;
use AOP\Pointcut\Api\TruePointcut;
use AOP\Tests\Fixtures\Api\Account;
use AOP\Tests\Fixtures\Api\AuditAdvice;
use AOP\Tests\Fixtures\Api\BeforeOnly;
use AOP\Tests\Fixtures\Api\Recorder;
use AOP\Tests\Fixtures\Api\Run;
use AOP\Tests\Fixtures\Api\SpecificThrows;
use AOP\Tests\Fixtures\Pointcut\PassThroughInterceptor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AdvisorAdapterRegistryTest extends TestCase
{
    #[Test]
    public function defaultAdaptersCoverTheAdviceInterfaces(): void
    {
        $registry = new AdvisorAdapterRegistry();

        self::assertSame(AdviceType::BEFORE, $registry->adviceType(new BeforeOnly()));
        self::assertSame(AdviceType::AFTER_THROWING, $registry->adviceType(new SpecificThrows()));
        self::assertSame(AdviceType::AROUND, $registry->adviceType(new PassThroughInterceptor()));
        self::assertTrue($registry->supports(new BeforeOnly()));
        self::assertInstanceOf(BeforeAdviceBridge::class, $registry->getInterceptor(new BeforeOnly()));
    }

    #[Test]
    public function aRecorderWithSeveralInterfacesUsesTheFirstMatchingAdapter(): void
    {
        // an around interceptor wins over before/afterReturning: the adapters are asked in the documented order
        $registry = new AdvisorAdapterRegistry();
        $recorder = new Recorder();

        self::assertSame(AdviceType::AROUND, $registry->adviceType($recorder));
    }

    #[Test]
    public function engineInterceptorsAreUsedAsTheyAre(): void
    {
        $registry = new AdvisorAdapterRegistry();
        $interceptor = new PassThroughInterceptor();

        self::assertSame($interceptor, $registry->getInterceptor($interceptor));
    }

    #[Test]
    public function unknownAdviceIsRejectedWithAHelpfulMessage(): void
    {
        $registry = new AdvisorAdapterRegistry();

        self::assertFalse($registry->supports(new AuditAdvice()));
        $this->expectException(UnknownAdviceTypeException::class);
        $this->expectExceptionMessage('No advisor adapter supports advice of type '.AuditAdvice::class);

        $registry->getInterceptor(new AuditAdvice());
    }

    #[Test]
    public function customAdaptersTeachTheRegistryNewAdviceTypes(): void
    {
        $registry = new AdvisorAdapterRegistry();
        $registry->registerAdvisorAdapter(new class implements AdvisorAdapterInterface {
            public function supportsAdvice(object $advice): bool
            {
                return $advice instanceof AuditAdvice;
            }

            public function getInterceptor(object $advice): MethodInterceptorInterface
            {
                \assert($advice instanceof AuditAdvice);

                return new class($advice) implements MethodInterceptorInterface {
                    public function __construct(private readonly AuditAdvice $advice)
                    {
                    }

                    public function invoke(MethodInvocation $invocation): mixed
                    {
                        $this->advice->audit($invocation->getSignature()->getName());

                        return $invocation->proceed();
                    }
                };
            }
        });
        $audit = new AuditAdvice();

        $advisor = (new DefaultPointcutAdvisor(TruePointcut::INSTANCE, $audit, adapters: $registry))->toAdvisor();
        $result = Run::call([$advisor], new Account(), 'getBalance');

        self::assertSame(100, $result);
        self::assertSame(['getBalance'], $audit->audited);
        self::assertSame(AdviceType::AROUND, $advisor->adviceType());
    }

    #[Test]
    public function typedAdaptersChooseTheOrderingSlot(): void
    {
        $registry = new AdvisorAdapterRegistry();
        $registry->registerAdvisorAdapter(new ClosureAdvisorAdapter(
            static fn (object $a): bool => $a instanceof AuditAdvice,
            static fn (object $a): MethodInterceptorInterface => new PassThroughInterceptor(),
            AdviceType::AFTER,
        ));

        self::assertSame(AdviceType::AFTER, $registry->adviceType(new AuditAdvice()));
    }

    #[Test]
    public function customAdaptersTakePrecedenceOverTheDefaults(): void
    {
        $registry = new AdvisorAdapterRegistry();
        $replacement = new PassThroughInterceptor();
        $registry->registerAdvisorAdapter(new ClosureAdvisorAdapter(
            static fn (object $a): bool => $a instanceof BeforeOnly,
            static fn (object $a): MethodInterceptorInterface => $replacement,
        ));

        self::assertSame($replacement, $registry->getInterceptor(new BeforeOnly()));
    }

    #[Test]
    public function anAdapterMayReturnTheSpringStyleInterceptorAndTheRegistryWrapsIt(): void
    {
        $spring = new Recorder('spring');
        $registry = new AdvisorAdapterRegistry();
        $registry->registerAdvisorAdapter(new class($spring) implements AdvisorAdapterInterface {
            public function __construct(private readonly SpringInterceptor $interceptor)
            {
            }

            public function supportsAdvice(object $advice): bool
            {
                return $advice instanceof AuditAdvice;
            }

            public function getInterceptor(object $advice): SpringInterceptor
            {
                return $this->interceptor;
            }
        });

        $interceptor = $registry->getInterceptor(new AuditAdvice());

        self::assertInstanceOf(InterceptorBridge::class, $interceptor);
        self::assertSame($spring, $interceptor->getInterceptor());

        $advisor = (new DefaultPointcutAdvisor(TruePointcut::INSTANCE, new AuditAdvice(), adapters: $registry))->toAdvisor();
        self::assertSame(100, Run::call([$advisor], new Account(), 'getBalance'));
        self::assertSame(['spring:in', 'spring:out'], $spring->events);
    }

    #[Test]
    public function aClosureAdapterMayReturnTheSpringStyleInterceptorToo(): void
    {
        $spring = new Recorder();
        $registry = new AdvisorAdapterRegistry();
        $registry->register(new ClosureAdvisorAdapter(
            static fn (object $a): bool => $a instanceof AuditAdvice,
            static fn (object $a): SpringInterceptor => $spring,
        ));

        $interceptor = $registry->getInterceptor(new AuditAdvice());

        self::assertInstanceOf(InterceptorBridge::class, $interceptor);
        self::assertSame($spring, $interceptor->getInterceptor());
    }

    #[Test]
    public function anAdapterReturningTheWrongInterfaceGetsAHelpfulError(): void
    {
        $registry = new AdvisorAdapterRegistry();
        // declared with the narrow engine type but returns the Spring-style one: the classic mix-up
        $registry->registerAdvisorAdapter(new class implements AdvisorAdapterInterface {
            public function supportsAdvice(object $advice): bool
            {
                return $advice instanceof AuditAdvice;
            }

            public function getInterceptor(object $advice): MethodInterceptorInterface
            {
                return new Recorder(); // @phpstan-ignore return.type
            }
        });

        try {
            $registry->getInterceptor(new AuditAdvice());
            self::fail('Expected LogicException');
        } catch (\LogicException $e) {
            self::assertStringContainsString('must return the engine-level '.MethodInterceptorInterface::class, $e->getMessage());
            self::assertStringContainsString(SpringInterceptor::class, $e->getMessage());
            self::assertStringContainsString('getInterceptor()', $e->getMessage());
            self::assertInstanceOf(\TypeError::class, $e->getPrevious());
        }
    }
}
