<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Api\Pointcut;

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
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid regular expression "(unclosed"');

        new RegexpMethodPointcut('(unclosed');
    }

    /**
     * @return iterable<string, array{string, string, string, bool}>
     */
    public static function delimited(): iterable
    {
        yield 'slash delimited, not anchored' => ['/Account::deposit/', Account::class, 'deposit', true];
        yield 'slash delimited, no match' => ['/Account::deposit/', Account::class, 'getBalance', false];
        yield 'case-insensitive flag' => ['/account::DEPOSIT/i', Account::class, 'deposit', true];
        yield 'without the flag it is case-sensitive' => ['/account::DEPOSIT/', Account::class, 'deposit', false];
        yield 'anchored by the user' => ['/^AOP.*Account::get/', Account::class, 'getBalance', true];
        yield 'anchored, no match' => ['/^Account::get/', Account::class, 'getBalance', false];
        yield 'hash delimiter' => ['#Fixtures\\\\Api\\\\Account::set#', Account::class, 'setBalance', true];
        yield 'brace delimiter with flags' => ['{ACCOUNT::SET}i', Account::class, 'setBalance', true];
        yield 'parentheses are delimiters, too' => ['(Account::(get|set)Balance)', Account::class, 'getBalance', true];
        yield 'several flags' => ["/account::\n?deposit/is", Account::class, 'deposit', true];
        yield 'a bare pattern starting with a dot is still bare' => ['.*::dep.*', Account::class, 'deposit', true];
        yield 'a bare alternation in parentheses that ends in a group stays bare' => ['(Account::(deposit))', Account::class, 'deposit', true];
        yield 'a bare character class start' => ['[A-Z].*::deposi[a-z]', Account::class, 'deposit', true];
        yield 'a bare pattern is still anchored' => ['Account::deposit', Account::class, 'deposit', false];
    }

    /**
     * @param class-string $class
     */
    #[Test]
    #[DataProvider('delimited')]
    public function acceptsFullyDelimitedPatterns(string $pattern, string $class, string $method, bool $expected): void
    {
        self::assertSame($expected, Pointcuts::matches(new RegexpMethodPointcut($pattern), new \ReflectionMethod($class, $method)));
        self::assertSame([$pattern], (new RegexpMethodPointcut($pattern))->getPatterns());
    }

    #[Test]
    public function delimitedPatternsWorkAsExcludedPatternsToo(): void
    {
        $pointcut = new RegexpMethodPointcut('/Account::/', '/::(set|deposit)/i');

        self::assertTrue(Pointcuts::matches($pointcut, new \ReflectionMethod(Account::class, 'getBalance')));
        self::assertFalse(Pointcuts::matches($pointcut, new \ReflectionMethod(Account::class, 'setBalance')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalid(): iterable
    {
        yield 'bare, unclosed group' => ['Account::(get'];
        yield 'bare, bad quantifier' => ['*Account'];
        yield 'delimited, unclosed group' => ['/Account::(get/'];
        yield 'delimited, bad quantifier' => ['/+Account/i'];
        yield 'delimited, bad class' => ['#[z-a]#'];
    }

    #[Test]
    #[DataProvider('invalid')]
    public function aPatternThatDoesNotCompileThrowsAndNamesThePattern(string $pattern): void
    {
        try {
            new RegexpMethodPointcut($pattern);
            self::fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('"'.$pattern.'"', $e->getMessage());
        }
    }

    #[Test]
    public function settersAndExcludedPatternsValidateToo(): void
    {
        $pointcut = new RegexpMethodPointcut();

        foreach ([static fn () => $pointcut->setPattern('/(/'), static fn () => $pointcut->setPatterns(['ok', '(']), static fn () => $pointcut->setExcludedPattern('[a-'), static fn () => new RegexpMethodPointcut('.*', '(')] as $call) {
            try {
                $call();
                self::fail('Expected InvalidArgumentException');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function aPointcutWithoutPatternsMatchesNothing(): void
    {
        self::assertFalse(Pointcuts::matches(new RegexpMethodPointcut(), new \ReflectionMethod(Account::class, 'getBalance')));
    }
}
