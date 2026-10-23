<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\JoinPoint;

use AOP\Interceptor\MethodInterceptorInterface;
use AOP\JoinPoint\MethodInvocation;
use AOP\JoinPoint\MethodSignature;
use AOP\Tests\Fixtures\Service\UserService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MethodInvocationTest extends TestCase
{
    #[Test]
    public function interceptorsRunInOrderAroundTheTarget(): void
    {
        $log = new \ArrayObject();

        $invocation = $this->invocation(
            [
                $this->interceptor(function (MethodInvocation $invocation) use ($log): mixed {
                    $log->append('first before');
                    $result = $invocation->proceed();
                    $log->append('first after');

                    return $result;
                }),
                $this->interceptor(function (MethodInvocation $invocation) use ($log): mixed {
                    $log->append('second before');
                    $result = $invocation->proceed();
                    $log->append('second after');

                    return $result;
                }),
            ],
            function (array $args) use ($log): string {
                $log->append('target');

                return 'result';
            },
            ['42'],
        );

        self::assertSame('result', $invocation->proceed());
        self::assertSame(
            ['first before', 'second before', 'target', 'second after', 'first after'],
            $log->getArrayCopy(),
        );
    }

    #[Test]
    public function targetInvokerReceivesArguments(): void
    {
        $received = null;

        $invocation = $this->invocation(
            [],
            function (array $args) use (&$received): null {
                $received = $args;

                return null;
            },
            [42, 'a', 'b'],
        );

        $invocation->proceed();

        self::assertSame([42, 'a', 'b'], $received);
    }

    #[Test]
    public function proceedWithNewArgumentsPassesThemToTarget(): void
    {
        $received = null;

        $invocation = $this->invocation(
            [
                $this->interceptor(
                    static fn (MethodInvocation $invocation): mixed => $invocation->proceed([7, 'changed']),
                ),
            ],
            function (array $args) use (&$received): null {
                $received = $args;

                return null;
            },
            [42, 'original'],
        );

        $invocation->proceed();

        self::assertSame([7, 'changed'], $received);
    }

    #[Test]
    public function interceptorMayProceedMoreThanOnce(): void
    {
        $calls = 0;

        $invocation = $this->invocation(
            [
                $this->interceptor(function (MethodInvocation $invocation): mixed {
                    $invocation->proceed();

                    return $invocation->proceed();
                }),
            ],
            function (array $args) use (&$calls): int {
                return ++$calls;
            },
            [],
        );

        self::assertSame(2, $invocation->proceed());
        self::assertSame(2, $calls);
    }

    #[Test]
    public function joinPointAccessorsExposeInvocationData(): void
    {
        $proxy = new UserService();
        $target = new UserService();
        $signature = MethodSignature::fromReflection(new \ReflectionMethod(UserService::class, 'findUser'));

        $invocation = new MethodInvocation(
            $proxy,
            $target,
            $signature,
            [42],
            [],
            static fn (array $args): null => null,
        );

        self::assertSame($proxy, $invocation->getThis());
        self::assertSame($target, $invocation->getTarget());
        self::assertSame($signature, $invocation->getSignature());
        self::assertSame([42], $invocation->getArgs());
        self::assertSame('UserService::findUser(..)', $invocation->toShortString());
    }

    /**
     * @param list<MethodInterceptorInterface> $interceptors
     * @param list<mixed>                      $args
     */
    private function invocation(array $interceptors, \Closure $targetInvoker, array $args): MethodInvocation
    {
        return new MethodInvocation(
            new UserService(),
            new UserService(),
            MethodSignature::fromReflection(new \ReflectionMethod(UserService::class, 'findUser')),
            $args,
            $interceptors,
            $targetInvoker,
        );
    }

    private function interceptor(\Closure $body): MethodInterceptorInterface
    {
        return new class($body) implements MethodInterceptorInterface {
            public function __construct(private readonly \Closure $body)
            {
            }

            public function invoke(MethodInvocation $invocation): mixed
            {
                return ($this->body)($invocation);
            }
        };
    }
}
