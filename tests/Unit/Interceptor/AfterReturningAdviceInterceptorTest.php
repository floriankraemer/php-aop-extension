<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Interceptor;

use AOP\Advice\AdviceType;
use AOP\Interceptor\AfterReturningAdviceInterceptor;
use AOP\Tests\Fixtures\Aspect\ProbeAspect;
use AOP\Tests\Fixtures\Chain;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AfterReturningAdviceInterceptorTest extends TestCase
{
    private ProbeAspect $probe;

    protected function setUp(): void
    {
        $this->probe = new ProbeAspect();
    }

    private function interceptor(string $method, ?string $returning = null, ?string $throwing = null): AfterReturningAdviceInterceptor
    {
        return new AfterReturningAdviceInterceptor(Chain::resolver($this->probe), ProbeAspect::class, $method, Chain::plan($method, AdviceType::AFTER_RETURNING, $returning, $throwing));
    }

    #[Test]
    public function passesValueAndCannotReplaceIt(): void
    {
        $result = Chain::invocation([$this->interceptor('returningString', returning: 'result')], static fn (): string => 'value')->proceed();

        self::assertSame('value', $result);
        self::assertSame([['returningString', 'value']], $this->probe->calls);
    }

    #[Test]
    public function skipsAdviceWhenReturnTypeDoesNotMatch(): void
    {
        $result = Chain::invocation([$this->interceptor('returningString', returning: 'result')], static fn (): int => 5)->proceed();

        self::assertSame(5, $result);
        self::assertSame([], $this->probe->calls);
    }

    #[Test]
    public function mixedMatchesNull(): void
    {
        Chain::invocation([$this->interceptor('returningAny', returning: 'result')], static fn (): null => null)->proceed();

        self::assertSame([['returningAny', null]], $this->probe->calls);
    }

    #[Test]
    public function doesNotRunOnException(): void
    {
        try {
            Chain::invocation([$this->interceptor('returningAny', returning: 'result')], static fn () => throw new \RuntimeException('x'))->proceed();
        } catch (\RuntimeException) {
        }

        self::assertSame([], $this->probe->calls);
    }

    #[Test]
    public function resolvesAspectLazilyOnFirstInvoke(): void
    {
        $count = 0;
        $resolver = new \AOP\Aspect\ArrayAspectInstanceResolver([ProbeAspect::class => function () use (&$count): ProbeAspect {
            ++$count;

            return $this->probe;
        }]);
        $interceptor = new AfterReturningAdviceInterceptor($resolver, ProbeAspect::class, 'returningAny', Chain::plan('returningAny', AdviceType::AFTER_RETURNING, 'result'));

        self::assertSame(0, $count);
        Chain::invocation([$interceptor], static fn (): int => 1)->proceed();
        Chain::invocation([$interceptor], static fn (): int => 1)->proceed();
        self::assertSame(1, $count);
    }
}
