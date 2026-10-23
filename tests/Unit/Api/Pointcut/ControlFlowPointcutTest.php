<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Api\Pointcut;

use AOP\Pointcut\Api\ControlFlowPointcut;
use AOP\Pointcut\RuntimeMatchContext;
use AOP\Tests\Fixtures\Api\Account;
use AOP\Tests\Fixtures\Api\Customer;
use AOP\Tests\Fixtures\Api\Flow;
use AOP\Tests\Fixtures\Api\SavingsAccount;
use AOP\Tests\Fixtures\Jp;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ControlFlowPointcutTest extends TestCase
{
    /**
     * @param list<array{object, string}> $frames outermost first
     */
    private function context(array $frames, object $current, string $method): RuntimeMatchContext
    {
        $stack = array_map(static fn (array $f) => Jp::invocation($f[0], $f[1]), $frames);
        $stack[] = Jp::invocation($current, $method);

        return new RuntimeMatchContext(Jp::invocation($current, $method), Jp::ctx($current::class, $method), new Flow($stack));
    }

    #[Test]
    public function isAlwaysAStaticMatchAndAnAlwaysRuntimePointcut(): void
    {
        $pointcut = new ControlFlowPointcut(Account::class);
        $ctx = Jp::ctx(Customer::class, 'name');

        self::assertTrue($pointcut->isRuntime());
        self::assertTrue($pointcut->matchesClass(null, $ctx));
        self::assertTrue($pointcut->matchesMethod(null, $ctx));
        self::assertSame([], $pointcut->bindings());
        self::assertFalse($pointcut->isCallerSensitive());
    }

    #[Test]
    public function matchesWhileAFrameOfTheClassIsActive(): void
    {
        $pointcut = new ControlFlowPointcut(Account::class);

        self::assertTrue($pointcut->matchesRuntime($this->context([[new Account(), 'getBalance']], new Customer(), 'name')));
        self::assertFalse($pointcut->matchesRuntime($this->context([[new Customer(), 'describe']], new Customer(), 'name')));
        self::assertSame(2, $pointcut->getEvaluations());
    }

    #[Test]
    public function matchesSubclassInstancesOfTheClass(): void
    {
        $pointcut = new ControlFlowPointcut(Account::class);

        self::assertTrue($pointcut->matchesRuntime($this->context([[new SavingsAccount(), 'interest']], new Customer(), 'name')));
    }

    #[Test]
    public function theMethodNameNarrowsTheFlow(): void
    {
        $pointcut = new ControlFlowPointcut(Account::class, 'get*');

        self::assertTrue($pointcut->matchesRuntime($this->context([[new Account(), 'getBalance']], new Customer(), 'name')));
        self::assertFalse($pointcut->matchesRuntime($this->context([[new Account(), 'deposit']], new Customer(), 'name')));
    }

    #[Test]
    public function theAdvisedJoinPointCountsUnlessBelow(): void
    {
        $inside = new ControlFlowPointcut(Account::class, 'deposit');
        $below = new ControlFlowPointcut(Account::class, 'deposit', true);
        $ctx = $this->context([], new Account(), 'deposit');

        self::assertTrue($inside->matchesRuntime($ctx));
        self::assertFalse($below->matchesRuntime($ctx));
        self::assertTrue($below->matchesRuntime($this->context([[new Account(), 'deposit']], new Customer(), 'name')));
    }

    #[Test]
    public function anEmptyControlFlowNeverMatches(): void
    {
        $pointcut = new ControlFlowPointcut(Account::class);
        $ctx = Jp::runtime(new Account(), 'getBalance');

        self::assertFalse($pointcut->matchesRuntime($ctx));
    }
}
