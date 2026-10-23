<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Api\Pointcut;

use AOP\Exception\PointcutSyntaxException;
use AOP\Pointcut\Api\ExpressionPointcut;
use AOP\Pointcut\Api\Pointcuts;
use AOP\Pointcut\Expression\PointcutExpressionCompiler;
use AOP\Tests\Fixtures\Api\Account;
use AOP\Tests\Fixtures\Api\SavingsAccount;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ExpressionPointcutTest extends TestCase
{
    #[Test]
    public function wrapsACompiledExpression(): void
    {
        $pointcut = new ExpressionPointcut('execution(* AOP\Tests\Fixtures\Api\Account::get*(..))');

        self::assertSame('execution(* AOP\Tests\Fixtures\Api\Account::get*(..))', $pointcut->getExpression());
        self::assertTrue(Pointcuts::matches($pointcut, new \ReflectionMethod(Account::class, 'getBalance')));
        self::assertFalse(Pointcuts::matches($pointcut, new \ReflectionMethod(Account::class, 'setBalance')));
        self::assertFalse($pointcut->isRuntime());
        self::assertFalse($pointcut->isCallerSensitive());
    }

    #[Test]
    public function supportsCombinators(): void
    {
        $pointcut = new ExpressionPointcut('execution(* **::*(..)) && !execution(* **::set*(..)) && target(AOP\Tests\Fixtures\Api\SavingsAccount)');
        $method = new \ReflectionMethod(Account::class, 'getBalance');

        self::assertFalse($pointcut->isRuntime());
        self::assertTrue(Pointcuts::matches($pointcut, $method, new \ReflectionClass(SavingsAccount::class), [], new SavingsAccount()));
        self::assertFalse(Pointcuts::matches($pointcut, $method, new \ReflectionClass(Account::class), [], new Account()));
        self::assertFalse(Pointcuts::matches($pointcut, new \ReflectionMethod(Account::class, 'setBalance'), new \ReflectionClass(SavingsAccount::class), [], new SavingsAccount()));
    }

    #[Test]
    public function exposesBindingsOfCandidateNames(): void
    {
        $pointcut = new ExpressionPointcut('execution(* **::deposit(..)) && args(amount, ..)', new PointcutExpressionCompiler(), ['amount' => 'int']);

        self::assertSame(['amount'], array_keys($pointcut->bindings()));
    }

    #[Test]
    public function aSyntaxErrorSurfacesWhenConstructing(): void
    {
        $this->expectException(PointcutSyntaxException::class);

        new ExpressionPointcut('execution(');
    }
}
