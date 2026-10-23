<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Interceptor;

use AOP\Advice\AdviceType;
use AOP\Interceptor\AfterThrowingAdviceInterceptor;
use AOP\Tests\Fixtures\Aspect\ProbeAspect;
use AOP\Tests\Fixtures\Chain;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AfterThrowingAdviceInterceptorTest extends TestCase
{
    private ProbeAspect $probe;

    protected function setUp(): void
    {
        $this->probe = new ProbeAspect();
    }

    private function interceptor(string $method, ?string $returning = null, ?string $throwing = null): AfterThrowingAdviceInterceptor
    {
        return new AfterThrowingAdviceInterceptor(Chain::resolver($this->probe), ProbeAspect::class, $method, Chain::plan($method, AdviceType::AFTER_THROWING, $returning, $throwing));
    }

    #[Test]
    public function runsForMatchingExceptionAndRethrowsSameInstance(): void
    {
        $e = new \InvalidArgumentException('bad');

        try {
            Chain::invocation([$this->interceptor('throwingInvalid', throwing: 'e')], static fn () => throw $e)->proceed();
            self::fail('expected exception');
        } catch (\Throwable $caught) {
            self::assertSame($e, $caught);
        }

        self::assertSame([['throwingInvalid', $e]], $this->probe->calls);
    }

    #[Test]
    public function skipsAdviceForOtherExceptionTypesButStillRethrows(): void
    {
        $e = new \RuntimeException('other');

        try {
            Chain::invocation([$this->interceptor('throwingInvalid', throwing: 'e')], static fn () => throw $e)->proceed();
            self::fail('expected exception');
        } catch (\RuntimeException $caught) {
            self::assertSame($e, $caught);
        }

        self::assertSame([], $this->probe->calls);
    }

    #[Test]
    public function doesNothingOnNormalReturn(): void
    {
        $result = Chain::invocation([$this->interceptor('throwingAny', throwing: 'e')], static fn (): string => 'ok')->proceed();

        self::assertSame('ok', $result);
        self::assertSame([], $this->probe->calls);
    }
}
