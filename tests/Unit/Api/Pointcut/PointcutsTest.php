<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Api\Pointcut;

use AOP\Pointcut\Api\ControlFlowPointcut;
use AOP\Pointcut\Api\NameMatchMethodPointcut;
use AOP\Pointcut\Api\Pointcuts;
use AOP\Pointcut\Api\RegexpMethodPointcut;
use AOP\Tests\Fixtures\Api\Account;
use AOP\Tests\Fixtures\Api\Flow;
use AOP\Tests\Fixtures\Jp;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PointcutsTest extends TestCase
{
    #[Test]
    public function unionAndIntersectionCombine(): void
    {
        $get = new NameMatchMethodPointcut('get*');
        $balance = new NameMatchMethodPointcut('*Balance');
        $getBalance = new \ReflectionMethod(Account::class, 'getBalance');
        $setBalance = new \ReflectionMethod(Account::class, 'setBalance');
        $deposit = new \ReflectionMethod(Account::class, 'deposit');

        $union = Pointcuts::union($get, $balance);
        $intersection = Pointcuts::intersection($get, $balance);

        self::assertTrue(Pointcuts::matches($union, $setBalance));
        self::assertFalse(Pointcuts::matches($union, $deposit));
        self::assertTrue(Pointcuts::matches($intersection, $getBalance));
        self::assertFalse(Pointcuts::matches($intersection, $setBalance));
    }

    #[Test]
    public function matchesInfersTheClassFromTheMethod(): void
    {
        $pointcut = new RegexpMethodPointcut('.*Account::getBalance');

        self::assertTrue(Pointcuts::matches($pointcut, new \ReflectionMethod(Account::class, 'getBalance')));
    }

    #[Test]
    public function matchesEvaluatesTheRuntimePartAgainstTheGivenTargetAndControlFlow(): void
    {
        $pointcut = new ControlFlowPointcut(Account::class, 'getBalance');
        $method = new \ReflectionMethod(Account::class, 'deposit');
        $account = new Account();
        $flow = new Flow([Jp::invocation($account, 'getBalance')]);

        self::assertFalse(Pointcuts::matches($pointcut, $method, null, [], $account));
        self::assertTrue(Pointcuts::matches($pointcut, $method, null, [], $account, $flow));
    }

    #[Test]
    public function matchesWorksForFunctions(): void
    {
        $pointcut = new NameMatchMethodPointcut('str*');

        self::assertTrue(Pointcuts::matches($pointcut, new \ReflectionFunction('strtoupper')));
        self::assertFalse(Pointcuts::matches($pointcut, new \ReflectionFunction('count')));
    }
}
