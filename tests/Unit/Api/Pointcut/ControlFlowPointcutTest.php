<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Api\Pointcut;

use AOP\JoinPoint\JoinPointKind;
use AOP\Pointcut\Api\ControlFlowPointcut;
use AOP\Pointcut\ControlFlowPointcuts;
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
    public function doesNotMatchCallJoinPointsByDefault(): void
    {
        $pointcut = new ControlFlowPointcut(Account::class);

        foreach ([JoinPointKind::METHOD_CALL, JoinPointKind::CONSTRUCTOR_CALL, JoinPointKind::FUNCTION_CALL, JoinPointKind::FIELD_GET] as $kind) {
            $ctx = Jp::ctx(Customer::class, 'name', $kind);
            self::assertFalse($pointcut->matchesClass(new \ReflectionClass(Customer::class), $ctx), $kind->value);
            self::assertFalse($pointcut->matchesMethod(new \ReflectionMethod(Customer::class, 'name'), $ctx), $kind->value);
        }
        $execution = Jp::ctx(Customer::class, 'name');
        self::assertTrue($pointcut->matchesClass(new \ReflectionClass(Customer::class), $execution));
        self::assertTrue($pointcut->matchesMethod(new \ReflectionMethod(Customer::class, 'name'), $execution));
    }

    #[Test]
    public function theKindsCanBeChosen(): void
    {
        $pointcut = new ControlFlowPointcut(Account::class, null, false, [JoinPointKind::METHOD_CALL]);

        self::assertTrue($pointcut->matchesClass(null, Jp::ctx(Customer::class, 'name', JoinPointKind::METHOD_CALL)));
        self::assertFalse($pointcut->matchesClass(null, Jp::ctx(Customer::class, 'name')));
    }

    #[Test]
    public function isControlFlowAwareAndNamesTheEntryExecutions(): void
    {
        $pointcut = new ControlFlowPointcut(Account::class, 'get*');
        $entries = ControlFlowPointcuts::of($pointcut);

        self::assertCount(1, $entries);
        $entry = $entries[0];
        $execution = Jp::ctx(Account::class, 'getBalance');
        $account = new \ReflectionClass(Account::class);
        self::assertTrue($entry->matchesClass($account, $execution) && $entry->matchesMethod(new \ReflectionMethod(Account::class, 'getBalance'), $execution));
        // another method name, a call join point, a class outside the hierarchy: not an entry
        self::assertFalse($entry->matchesMethod(new \ReflectionMethod(Account::class, 'deposit'), Jp::ctx(Account::class, 'deposit')));
        self::assertFalse($entry->matchesClass($account, Jp::ctx(Account::class, 'getBalance', JoinPointKind::METHOD_CALL)));
        self::assertFalse($entry->matchesClass(new \ReflectionClass(Customer::class), Jp::ctx(Customer::class, 'name')));
        // a subclass of the class is an entry, too
        $savings = Jp::ctx(SavingsAccount::class, 'getBalance');
        self::assertTrue($entry->matchesClass(new \ReflectionClass(SavingsAccount::class), $savings));
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

    #[Test]
    public function theEntryPointcutIsPurelyStaticAndNeverBindsOrDependsOnTheCaller(): void
    {
        $entry = ControlFlowPointcuts::of(new ControlFlowPointcut(Account::class, null))[0];

        self::assertFalse($entry->isRuntime());
        self::assertFalse($entry->isCallerSensitive());
        self::assertSame([], $entry->bindings());
        self::assertTrue($entry->matchesRuntime(Jp::runtime(new Account(), 'getBalance')));
    }

    #[Test]
    public function needsFieldInterceptionOnlyWhenItsKindsInclude(): void
    {
        self::assertFalse((new ControlFlowPointcut(Account::class))->requiresFieldInterception());
        self::assertTrue((new ControlFlowPointcut(Account::class, null, false, [JoinPointKind::FIELD_GET]))->requiresFieldInterception());
    }
}
