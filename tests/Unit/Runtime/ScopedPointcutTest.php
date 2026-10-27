<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Runtime;

use AOP\Aspect\AspectScope;
use AOP\JoinPoint\JoinPointKind;
use AOP\Pointcut\Api\ControlFlowPointcut;
use AOP\Pointcut\Expression\PointcutExpressionCompiler;
use AOP\Pointcut\PointcutInterface;
use AOP\Runtime\ControlFlowStack;
use AOP\Runtime\ScopedPointcut;
use AOP\Tests\Fixtures\Api\Account;
use AOP\Tests\Fixtures\Jp;
use AOP\Tests\Fixtures\Runtime\Aspect\PerCflowAspect;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScopedPointcutTest extends TestCase
{
    private static function compile(string $expression): PointcutInterface
    {
        return (new PointcutExpressionCompiler())->compile($expression);
    }

    #[Test]
    public function exposesTheWrappedPointcutAndItsFlowEntriesAndFieldNeeds(): void
    {
        $inner = new ControlFlowPointcut(Account::class, null, false, [JoinPointKind::FIELD_GET]);
        $scoped = new ScopedPointcut($inner, PerCflowAspect::class, AspectScope::SINGLETON, null, new ControlFlowStack());

        self::assertSame($inner, $scoped->inner());
        self::assertCount(1, $scoped->cflowPointcuts());
        self::assertTrue($scoped->requiresFieldInterception());
        self::assertFalse((new ScopedPointcut(self::compile('execution(* *(..))'), PerCflowAspect::class, AspectScope::SINGLETON, null, new ControlFlowStack()))->requiresFieldInterception());
    }

    #[Test]
    public function aPercflowAspectOnlyAdvisesInsideItsFlow(): void
    {
        $flow = new ControlFlowStack();
        $scoped = new ScopedPointcut(self::compile('execution(* AOP\Tests\Fixtures\Api\Account::*(..))'), PerCflowAspect::class, AspectScope::PER_CFLOW, null, $flow);
        $ctx = Jp::runtime(new Account(), 'getBalance');

        self::assertTrue($scoped->isRuntime(), 'the flow is a runtime fact');
        self::assertFalse($scoped->matchesRuntime($ctx), 'no flow of the aspect is active');

        $flow->pushToken(PerCflowAspect::class);

        self::assertTrue($scoped->matchesRuntime($ctx));
    }

    #[Test]
    public function aRestrictionNarrowsStaticallyAndMakesTheScopedPointcutCallerSensitiveWhenItIs(): void
    {
        $restriction = self::compile('within(AOP\Tests\Fixtures\Api\Account)');
        $scoped = new ScopedPointcut(self::compile('execution(* *(..))'), PerCflowAspect::class, AspectScope::PER_THIS, $restriction, new ControlFlowStack());
        $account = new \ReflectionClass(Account::class);
        $ctx = Jp::ctx(Account::class, 'getBalance');

        self::assertTrue($scoped->isCallerSensitive());
        self::assertTrue($scoped->matchesClass($account, $ctx));
        self::assertFalse($scoped->matchesClass(new \ReflectionClass(\stdClass::class), Jp::ctx(\stdClass::class)));
        self::assertTrue($scoped->matchesMethod(new \ReflectionMethod(Account::class, 'getBalance'), $ctx));
        self::assertFalse((new ScopedPointcut(self::compile('execution(* *(..))'), PerCflowAspect::class, AspectScope::PER_THIS, null, new ControlFlowStack()))->isCallerSensitive());
    }
}
