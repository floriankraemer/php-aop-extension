<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Api\Advice;

use AOP\Advice\Api\Interceptor\ThrowsAdviceBridge;
use AOP\Advice\Api\ThrowsAdviceInterface;
use AOP\Exception\AspectDefinitionException;
use AOP\Interceptor\MethodInterceptorInterface;
use AOP\Tests\Fixtures\Api\Account;
use AOP\Tests\Fixtures\Api\BadThrowsArity;
use AOP\Tests\Fixtures\Api\BadThrowsFirst;
use AOP\Tests\Fixtures\Api\BadThrowsType;
use AOP\Tests\Fixtures\Api\DuplicateThrows;
use AOP\Tests\Fixtures\Api\EmptyThrows;
use AOP\Tests\Fixtures\Api\Failure;
use AOP\Tests\Fixtures\Api\FailureException;
use AOP\Tests\Fixtures\Api\SpecificThrows;
use AOP\Tests\Fixtures\Jp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ThrowsAdviceTest extends TestCase
{
    /**
     * @param list<MethodInterceptorInterface> $chain
     */
    private function failing(array $chain, string $method): \Throwable
    {
        $account = new Account();
        $invocation = Jp::invocation($account, $method, [], $chain, static fn (array $a): mixed => $account->{$method}());
        try {
            $invocation->proceed();
        } catch (\Throwable $e) {
            return $e;
        }
        self::fail('expected an exception');
    }

    /**
     * @return iterable<string, array{string, class-string<\Throwable>, string}>
     */
    public static function dispatch(): iterable
    {
        yield 'exact class' => ['fail', \InvalidArgumentException::class, 'invalid-argument'];
        yield 'parent class with the join point' => ['failLogic', \LogicException::class, 'logic:failLogic'];
        yield 'union type member' => ['failRuntime', \UnexpectedValueException::class, 'runtime-or-unexpected'];
        yield 'falls back to Throwable' => ['failOther', \DomainException::class, 'logic:failOther'];
    }

    /**
     * @param class-string<\Throwable> $thrown
     */
    #[Test]
    #[DataProvider('dispatch')]
    public function dispatchesToTheMostSpecificHandler(string $method, string $thrown, string $expected): void
    {
        $advice = new SpecificThrows();

        $e = $this->failing([new ThrowsAdviceBridge($advice)], $method);

        self::assertInstanceOf($thrown, $e);
        self::assertSame([$expected], $advice->handled, 'exactly one handler runs, and the exception is rethrown');
    }

    #[Test]
    public function fallsBackToThrowableWhenNothingMoreSpecificMatches(): void
    {
        $advice = new class implements ThrowsAdviceInterface {
            /** @var list<string> */
            public array $handled = [];

            public function afterThrowing(\InvalidArgumentException $e): void
            {
                $this->handled[] = 'invalid';
            }

            public function afterThrowingAny(\Throwable $e): void
            {
                $this->handled[] = 'any';
            }
        };

        $this->failing([new ThrowsAdviceBridge($advice)], 'failOther');
        $this->failing([new ThrowsAdviceBridge($advice)], 'fail');

        self::assertSame(['any', 'invalid'], $advice->handled);
    }

    #[Test]
    public function exceptionsWithoutHandlerPassThroughUntouched(): void
    {
        $advice = new class implements ThrowsAdviceInterface {
            public int $calls = 0;

            public function afterThrowing(\InvalidArgumentException $e): void
            {
                ++$this->calls;
            }
        };

        $e = $this->failing([new ThrowsAdviceBridge($advice)], 'failOther');

        self::assertInstanceOf(\DomainException::class, $e);
        self::assertSame(0, $advice->calls);
    }

    #[Test]
    public function interfacesOfTheExceptionAreCandidatesAfterItsClasses(): void
    {
        $advice = new class implements ThrowsAdviceInterface {
            /** @var list<string> */
            public array $handled = [];

            public function afterThrowing(Failure $e): void
            {
                $this->handled[] = 'failure';
            }
        };
        $account = new Account();
        $thrower = static fn (array $a): never => throw new FailureException('x');

        try {
            Jp::invocation($account, 'getBalance', [], [new ThrowsAdviceBridge($advice)], $thrower)->proceed();
            self::fail();
        } catch (FailureException) {
        }

        self::assertSame(['failure'], $advice->handled);
    }

    #[Test]
    public function aHandlerThatThrowsReplacesTheException(): void
    {
        $advice = new class implements ThrowsAdviceInterface {
            public function afterThrowing(\InvalidArgumentException $e): void
            {
                throw new \OverflowException('translated', 0, $e);
            }
        };

        $e = $this->failing([new ThrowsAdviceBridge($advice)], 'fail');

        self::assertInstanceOf(\OverflowException::class, $e);
        self::assertInstanceOf(\InvalidArgumentException::class, $e->getPrevious());
    }

    #[Test]
    public function successfulCallsAreUntouched(): void
    {
        $advice = new SpecificThrows();
        $account = new Account();

        $result = Jp::invocation($account, 'getBalance', [], [new ThrowsAdviceBridge($advice)], static fn (array $a): int => 5)->proceed();

        self::assertSame(5, $result);
        self::assertSame([], $advice->handled);
    }

    /**
     * @return iterable<string, array{class-string<ThrowsAdviceInterface>, string}>
     */
    public static function invalid(): iterable
    {
        yield 'no handler' => [EmptyThrows::class, 'needs at least one public method named afterThrowing*'];
        yield 'wrong arity' => [BadThrowsArity::class, 'must take (\Throwable $e) or (JoinPointInterface $joinPoint, \Throwable $e)'];
        yield 'not an exception' => [BadThrowsType::class, '"stdClass" is not'];
        yield 'two handlers for one type' => [DuplicateThrows::class, 'both handle LogicException'];
        yield 'bad join point parameter' => [BadThrowsFirst::class, 'must be typed JoinPointInterface'];
    }

    /**
     * @param class-string<ThrowsAdviceInterface> $class
     */
    #[Test]
    #[DataProvider('invalid')]
    public function rejectsUnusableAdvice(string $class, string $message): void
    {
        $this->expectException(AspectDefinitionException::class);
        $this->expectExceptionMessage($message);

        new ThrowsAdviceBridge(new $class());
    }
}
