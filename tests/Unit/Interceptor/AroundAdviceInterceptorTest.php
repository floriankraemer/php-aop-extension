<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Interceptor;

use AOP\Advice\AdviceType;
use AOP\Interceptor\AroundAdviceInterceptor;
use AOP\Tests\Fixtures\Aspect\ProbeAspect;
use AOP\Tests\Fixtures\Chain;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AroundAdviceInterceptorTest extends TestCase
{
    private ProbeAspect $probe;

    protected function setUp(): void
    {
        $this->probe = new ProbeAspect();
    }

    private function interceptor(string $method, ?string $returning = null, ?string $throwing = null): AroundAdviceInterceptor
    {
        return new AroundAdviceInterceptor(Chain::resolver($this->probe), ProbeAspect::class, $method, Chain::plan($method, AdviceType::AROUND, $returning, $throwing));
    }

    #[Test]
    public function returnsAdviceResult(): void
    {
        $this->probe->aroundMode = 'replace';

        self::assertSame('HELLO', Chain::invocation([$this->interceptor('around')], static fn (): string => 'hello')->proceed());
    }

    #[Test]
    public function proceedWithNewArgsAltersTargetArguments(): void
    {
        $this->probe->aroundMode = 'alter';
        $seen = null;

        Chain::invocation([$this->interceptor('around')], static function (array $args) use (&$seen): null {
            $seen = $args;

            return null;
        })->proceed();

        self::assertSame(['Changed'], $seen);
    }

    #[Test]
    public function proceedTwiceInvokesTargetTwice(): void
    {
        $this->probe->aroundMode = 'twice';
        $count = 0;

        $result = Chain::invocation([$this->interceptor('around')], static function () use (&$count): int {
            return ++$count;
        })->proceed();

        self::assertSame([1, 2], $result);
        self::assertSame(2, $count);
    }

    #[Test]
    public function notProceedingSkipsTarget(): void
    {
        $this->probe->aroundMode = 'skip';
        $called = false;

        $result = Chain::invocation([$this->interceptor('around')], static function () use (&$called): null {
            $called = true;

            return null;
        })->proceed();

        self::assertSame('skipped', $result);
        self::assertFalse($called);
    }

    #[Test]
    public function alteredArgsReachInnerInterceptors(): void
    {
        $this->probe->aroundMode = 'alter';
        $inner = new class implements \AOP\Interceptor\MethodInterceptorInterface {
            /** @var array<int|string, mixed> */
            public array $args = [];

            public function invoke(\AOP\JoinPoint\MethodInvocation $invocation): mixed
            {
                $this->args = $invocation->getArgs();

                return $invocation->proceed();
            }
        };

        Chain::invocation([$this->interceptor('around'), $inner], static fn (): null => null)->proceed();

        self::assertSame(['Changed'], $inner->args);
    }
}
