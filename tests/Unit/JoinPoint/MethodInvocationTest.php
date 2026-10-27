<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\JoinPoint;

use AOP\Interceptor\MethodInterceptorInterface;
use AOP\JoinPoint\FunctionSignature;
use AOP\JoinPoint\JoinPointData;
use AOP\JoinPoint\JoinPointKind;
use AOP\JoinPoint\MethodInvocation;
use AOP\JoinPoint\MethodSignature;
use AOP\JoinPoint\SourceLocation;
use AOP\JoinPoint\StaticPart;
use AOP\Tests\Fixtures\Jp;
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
    public function everyProceedRunsTheRestOfTheChainFromItsOwnPosition(): void
    {
        $inner = 0;
        $targets = 0;

        $invocation = $this->invocation(
            [
                $this->interceptor(function (MethodInvocation $invocation): mixed {
                    $invocation->proceed();

                    return $invocation->proceed();
                }),
                $this->interceptor(function (MethodInvocation $invocation) use (&$inner): mixed {
                    ++$inner;

                    return $invocation->proceed();
                }),
            ],
            function (array $args) use (&$targets): int {
                return ++$targets;
            },
            [],
        );

        self::assertSame(2, $invocation->proceed());
        self::assertSame(2, $inner, 'the second proceed() reaches the inner interceptor again');
        self::assertSame(2, $targets);
    }

    #[Test]
    public function replacementArgumentsMissingAByReferenceKeyLeaveThatArgumentOut(): void
    {
        $first = 'a';
        $second = 'b';
        $received = null;
        $invocation = new MethodInvocation(
            Jp::data(new UserService(), 'findUser'),
            [&$first, &$second],
            [$this->interceptor(static fn (MethodInvocation $i): mixed => $i->proceed([0 => 'x']))],
            static function (array $a) use (&$received): null {
                $received = $a;

                return null;
            },
        );

        $invocation->proceed();

        self::assertSame(['x'], $received);
        self::assertSame('x', $first);
        self::assertSame('b', $second);
    }

    #[Test]
    public function joinPointAccessorsExposeInvocationData(): void
    {
        $caller = new UserService();
        $target = new UserService();
        $signature = MethodSignature::fromReflection(new \ReflectionMethod(UserService::class, 'findUser'));
        $staticPart = new StaticPart(JoinPointKind::METHOD_CALL, $signature);
        $enclosing = new StaticPart(JoinPointKind::FUNCTION_EXECUTION, new FunctionSignature('App\\run'));
        $location = new SourceLocation('/app/run.php', 12, null, 'App\\run');

        $invocation = new MethodInvocation(
            new JoinPointData(JoinPointKind::METHOD_CALL, $caller, $target, $signature, $staticPart, $enclosing, $location),
            [42],
            [],
            static fn (array $args): null => null,
        );

        self::assertSame(JoinPointKind::METHOD_CALL, $invocation->getKind());
        self::assertSame($caller, $invocation->getThis());
        self::assertSame($target, $invocation->getTarget());
        self::assertSame($signature, $invocation->getSignature());
        self::assertSame($staticPart, $invocation->getStaticPart());
        self::assertSame($enclosing, $invocation->getEnclosingStaticPart());
        self::assertSame($location, $invocation->getSourceLocation());
        self::assertSame([42], $invocation->getArgs());
        self::assertSame('UserService::findUser(..)', $invocation->toShortString());
        self::assertSame('method-call(AOP\Tests\Fixtures\Service\UserService::findUser(int, ...string))', $invocation->toLongString());
    }

    #[Test]
    public function staticJoinPointHasNoThisAndTarget(): void
    {
        $signature = new FunctionSignature('App\\fmt');
        $invocation = new MethodInvocation(
            new JoinPointData(JoinPointKind::FUNCTION_EXECUTION, null, null, $signature, new StaticPart(JoinPointKind::FUNCTION_EXECUTION, $signature)),
            ['x'],
            [$this->interceptor(static fn (MethodInvocation $i): string => strtoupper(is_string($r = $i->proceed()) ? $r : ''))],
            static fn (array $args): string => 'fmt:'.(is_string($args[0]) ? $args[0] : ''),
        );

        self::assertNull($invocation->getThis());
        self::assertNull($invocation->getTarget());
        self::assertNull($invocation->getEnclosingStaticPart());
        self::assertNull($invocation->getSourceLocation());
        self::assertSame('FMT:X', $invocation->proceed());
    }

    #[Test]
    public function byReferenceArgumentsWriteThroughWhenProceedingWithReplacements(): void
    {
        $value = 'orig';
        $args = [&$value];
        $invocation = new MethodInvocation(
            Jp::data(new UserService(), 'findUser'),
            $args,
            [$this->interceptor(static fn (MethodInvocation $i): mixed => $i->proceed(['replaced']))],
            static fn (array $a): null => null,
        );

        $invocation->proceed();

        self::assertSame('replaced', $value);
    }

    #[Test]
    public function getArgsHandsOutValuesNotReferences(): void
    {
        $value = 'orig';
        $invocation = new MethodInvocation(Jp::data(new UserService(), 'findUser'), [&$value], [], static fn (array $a): null => null);

        $copy = $invocation->getArgs();
        $copy[0] = 'changed';

        self::assertSame('orig', $value);
    }

    /**
     * @param list<MethodInterceptorInterface> $interceptors
     * @param list<mixed>                      $args
     */
    private function invocation(array $interceptors, \Closure $targetInvoker, array $args): MethodInvocation
    {
        return Jp::invocation(new UserService(), 'findUser', $args, $interceptors, $targetInvoker);
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
