<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Api\Pointcut;

use AOP\Exception\PointcutSyntaxException;
use AOP\Pointcut\Api\Pointcuts;
use AOP\Pointcut\Api\RegexpMethodPointcut;
use AOP\Tests\Fixtures\Api\Account;
use AOP\Tests\Fixtures\Api\SavingsAccount;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RegexpMethodPointcutTest extends TestCase
{
    /**
     * @return iterable<string, array{string|list<string>, string, string, bool}>
     */
    public static function cases(): iterable
    {
        yield 'any setter' => ['.*::set.*', Account::class, 'setBalance', true];
        yield 'any setter, other method' => ['.*::set.*', Account::class, 'getBalance', false];
        yield 'must match the whole name' => ['set.*', Account::class, 'setBalance', false];
        yield 'fully qualified class' => ['AOP\\\\Tests\\\\Fixtures\\\\Api\\\\Account::deposit', Account::class, 'deposit', true];
        yield 'prefix of the class only' => ['AOP\\\\Tests\\\\Fixtures\\\\Api\\\\.*::get.*', Account::class, 'getBalance', true];
        yield 'one of several' => [['.*::nothing', '.*::dep.*'], Account::class, 'deposit', true];
        yield 'alternation' => ['.*::(get|set)Balance', Account::class, 'setBalance', true];
        yield 'delimiter chars need no escaping' => ['.*/?~?::deposit|.*::fail.*', Account::class, 'failLogic', true];
    }

    /**
     * @param string|list<string> $patterns
     * @param class-string        $class
     */
    #[Test]
    #[DataProvider('cases')]
    public function matchesAgainstClassAndMethod(string|array $patterns, string $class, string $method, bool $expected): void
    {
        self::assertSame($expected, Pointcuts::matches(new RegexpMethodPointcut($patterns), new \ReflectionMethod($class, $method)));
    }

    #[Test]
    public function excludedPatternsWin(): void
    {
        $pointcut = new RegexpMethodPointcut('.*::(get|set).*', '.*::setBalance');

        self::assertTrue(Pointcuts::matches($pointcut, new \ReflectionMethod(Account::class, 'getBalance')));
        self::assertFalse(Pointcuts::matches($pointcut, new \ReflectionMethod(Account::class, 'setBalance')));
        self::assertSame(['.*::setBalance'], $pointcut->getExcludedPatterns());
        self::assertSame(['.*::(get|set).*'], $pointcut->getPatterns());
    }

    #[Test]
    public function settersReplaceThePatterns(): void
    {
        $pointcut = new RegexpMethodPointcut('.*::get.*');
        $pointcut->setPattern('.*::set.*')->setExcludedPattern('.*::nothing');
        $set = new \ReflectionMethod(Account::class, 'setBalance');

        self::assertTrue(Pointcuts::matches($pointcut, $set));
        self::assertFalse(Pointcuts::matches($pointcut, new \ReflectionMethod(Account::class, 'getBalance')));
        self::assertSame(['.*::set.*'], $pointcut->getPatterns());
    }

    #[Test]
    public function matchesTheRuntimeClassToo(): void
    {
        $pointcut = new RegexpMethodPointcut('.*SavingsAccount::deposit');
        $method = new \ReflectionMethod(SavingsAccount::class, 'deposit'); // declared in Account

        self::assertTrue(Pointcuts::matches($pointcut, $method, new \ReflectionClass(SavingsAccount::class)));
        self::assertFalse(Pointcuts::matches($pointcut, new \ReflectionMethod(Account::class, 'deposit'), new \ReflectionClass(Account::class)));
    }

    #[Test]
    public function matchesFunctionsByTheirName(): void
    {
        $pointcut = new RegexpMethodPointcut('str(to)?upper');

        self::assertTrue(Pointcuts::matches($pointcut, new \ReflectionFunction('strtoupper')));
        self::assertFalse(Pointcuts::matches($pointcut, new \ReflectionFunction('strtolower')));
    }

    #[Test]
    public function rejectsAnInvalidExpression(): void
    {
        $this->expectException(PointcutSyntaxException::class);
        $this->expectExceptionMessage('Invalid regular expression "(unclosed"');

        new RegexpMethodPointcut('(unclosed');
    }

    #[Test]
    public function aPointcutWithoutPatternsMatchesNothing(): void
    {
        self::assertFalse(Pointcuts::matches(new RegexpMethodPointcut(), new \ReflectionMethod(Account::class, 'getBalance')));
    }
}
