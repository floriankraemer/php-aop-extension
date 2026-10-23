<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Api\Pointcut;

use AOP\Pointcut\Api\ApiPointcut;
use AOP\Pointcut\Api\ClassFilterInterface;
use AOP\Pointcut\Api\ComposablePointcut;
use AOP\Pointcut\Api\DynamicMethodMatcherPointcut;
use AOP\Pointcut\Api\NameMatchMethodPointcut;
use AOP\Pointcut\Api\Pointcuts;
use AOP\Pointcut\Api\TrueMethodMatcher;
use AOP\Pointcut\Api\TruePointcut;
use AOP\Tests\Fixtures\Api\Account;
use AOP\Tests\Fixtures\Api\SavingsAccount;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ComposablePointcutTest extends TestCase
{
    /**
     * @param class-string $class
     * @param list<mixed>  $args
     */
    private function advised(ComposablePointcut $pointcut, string $class, string $method, array $args = []): bool
    {
        return Pointcuts::matches($pointcut, new \ReflectionMethod($class, $method), new \ReflectionClass($class), $args, new $class());
    }

    private function onlySavings(): ClassFilterInterface
    {
        return new class implements ClassFilterInterface {
            public function matches(\ReflectionClass $class): bool
            {
                return SavingsAccount::class === $class->getName();
            }
        };
    }

    #[Test]
    public function startsAsMatchEverything(): void
    {
        $pointcut = new ComposablePointcut();

        self::assertTrue($this->advised($pointcut, Account::class, 'getBalance'));
        self::assertFalse($pointcut->isRuntime());
    }

    #[Test]
    public function intersectionKeepsWhatBothMatch(): void
    {
        $pointcut = (new ComposablePointcut())
            ->intersection(new NameMatchMethodPointcut('get*'))
            ->intersection($this->onlySavings());

        self::assertTrue($this->advised($pointcut, SavingsAccount::class, 'getBalance'));
        self::assertFalse($this->advised($pointcut, Account::class, 'getBalance'));
        self::assertFalse($this->advised($pointcut, SavingsAccount::class, 'interest'));
    }

    #[Test]
    public function unionKeepsWhatEitherMatches(): void
    {
        $pointcut = (new ComposablePointcut(new NameMatchMethodPointcut('get*')))
            ->union(new NameMatchMethodPointcut('set*'));

        self::assertTrue($this->advised($pointcut, Account::class, 'getBalance'));
        self::assertTrue($this->advised($pointcut, Account::class, 'setBalance'));
        self::assertFalse($this->advised($pointcut, Account::class, 'deposit'));
    }

    #[Test]
    public function aClassFilterOperandStandsForAllMethodsOfThoseClasses(): void
    {
        $pointcut = (new ComposablePointcut(new NameMatchMethodPointcut('getBalance')))->union($this->onlySavings());

        self::assertTrue($this->advised($pointcut, Account::class, 'getBalance'));
        self::assertFalse($this->advised($pointcut, Account::class, 'deposit'));
        self::assertTrue($this->advised($pointcut, SavingsAccount::class, 'interest'));
    }

    #[Test]
    public function aMethodMatcherOperandStandsForTheseMethodsOnAllClasses(): void
    {
        $matcher = (new NameMatchMethodPointcut('deposit'))->getMethodMatcher();
        $pointcut = (new ComposablePointcut($this->noneMatching()))->union($matcher);

        self::assertTrue($this->advised($pointcut, Account::class, 'deposit'));
        self::assertFalse($this->advised($pointcut, Account::class, 'getBalance'));
        self::assertSame($pointcut, $pointcut->intersection(TrueMethodMatcher::INSTANCE));
    }

    private function noneMatching(): ApiPointcut
    {
        return new ApiPointcut($this->onlySavings(), TrueMethodMatcher::INSTANCE);
    }

    #[Test]
    public function aRuntimePartMakesTheCompositionRuntime(): void
    {
        $dynamic = new class extends DynamicMethodMatcherPointcut {
            public function matchesArguments(\ReflectionFunctionAbstract $function, ?\ReflectionClass $class, array $args): bool
            {
                return 7 === ($args[0] ?? null);
            }
        };
        $pointcut = (new ComposablePointcut(new NameMatchMethodPointcut('deposit')))->intersection($dynamic);

        self::assertTrue($pointcut->isRuntime());
        self::assertTrue($this->advised($pointcut, Account::class, 'deposit', [7]));
        self::assertFalse($this->advised($pointcut, Account::class, 'deposit', [8]));
        self::assertFalse($this->advised($pointcut, Account::class, 'getBalance', [7]));
    }

    #[Test]
    public function composablePointcutsNestAndTakeAnyPointcut(): void
    {
        $inner = (new ComposablePointcut())->intersection(new NameMatchMethodPointcut('get*'));
        $outer = (new ComposablePointcut(TruePointcut::INSTANCE))->intersection($inner)->union(Pointcuts::intersection(new NameMatchMethodPointcut('set*'), TruePointcut::INSTANCE));

        self::assertTrue($this->advised($outer, Account::class, 'getBalance'));
        self::assertTrue($this->advised($outer, Account::class, 'setBalance'));
        self::assertFalse($this->advised($outer, Account::class, 'deposit'));
        self::assertSame([], $outer->bindings());
        self::assertFalse($outer->isCallerSensitive());
    }
}
