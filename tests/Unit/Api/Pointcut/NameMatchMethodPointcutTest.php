<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Api\Pointcut;

use AOP\Pointcut\Api\NameMatchMethodPointcut;
use AOP\Pointcut\Api\Pointcuts;
use AOP\Tests\Fixtures\Api\Account;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function AOP\Tests\Fixtures\Api\get_value;

final class NameMatchMethodPointcutTest extends TestCase
{
    /**
     * @return iterable<string, array{string|list<string>, string, bool}>
     */
    public static function cases(): iterable
    {
        yield 'exact' => ['deposit', 'deposit', true];
        yield 'exact, other' => ['deposit', 'getBalance', false];
        yield 'prefix glob' => ['get*', 'getBalance', true];
        yield 'suffix glob' => ['*Balance', 'setBalance', true];
        yield 'infix glob' => ['get*ance', 'getBalance', true];
        yield 'both ends' => ['*alan*', 'getBalance', true];
        yield 'everything' => ['*', 'fail', true];
        yield 'case sensitive' => ['getbalance', 'getBalance', false];
        yield 'list' => [['nothing', 'dep*'], 'deposit', true];
        yield 'regex chars are literal' => ['get.alance', 'getBalance', false];
    }

    /**
     * @param string|list<string> $names
     */
    #[Test]
    #[DataProvider('cases')]
    public function matchesNames(string|array $names, string $method, bool $expected): void
    {
        self::assertSame($expected, Pointcuts::matches(new NameMatchMethodPointcut($names), new \ReflectionMethod(Account::class, $method)));
    }

    #[Test]
    public function mappedNamesCanBeSetAndAdded(): void
    {
        $pointcut = new NameMatchMethodPointcut();
        self::assertFalse(Pointcuts::matches($pointcut, new \ReflectionMethod(Account::class, 'deposit')));

        $pointcut->setMappedNames('deposit', 'get*')->addMethodName('fail');

        self::assertSame(['deposit', 'get*', 'fail'], $pointcut->getMappedNames());
        self::assertTrue(Pointcuts::matches($pointcut, new \ReflectionMethod(Account::class, 'fail')));

        $pointcut->setMappedName('setBalance');
        self::assertSame(['setBalance'], $pointcut->getMappedNames());
        self::assertFalse(Pointcuts::matches($pointcut, new \ReflectionMethod(Account::class, 'deposit')));
    }

    #[Test]
    public function matchesFunctionsByFullAndShortName(): void
    {
        require_once __DIR__.'/../../../Fixtures/Api/functions.php';
        $function = new \ReflectionFunction('AOP\Tests\Fixtures\Api\get_value');

        self::assertTrue(Pointcuts::matches(new NameMatchMethodPointcut('get_*'), $function));
        self::assertTrue(Pointcuts::matches(new NameMatchMethodPointcut('AOP\Tests\Fixtures\Api\get_*'), $function));
        self::assertFalse(Pointcuts::matches(new NameMatchMethodPointcut('set_*'), $function));
        self::assertSame(2, get_value());
    }
}
