<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Pointcut;

use AOP\Exception\BindingException;
use AOP\JoinPoint\JoinPointKind;
use AOP\Pointcut\AdviceExecutionPointcut;
use AOP\Pointcut\HandlerPointcut;
use AOP\Pointcut\StaticMatchContext;
use AOP\Tests\Fixtures\Pointcut\Callee;
use AOP\Tests\Fixtures\Pointcut\Caller;
use AOP\Tests\Fixtures\Pointcut\Conditions;
use AOP\Tests\Fixtures\Pointcut\DomainError;
use AOP\Tests\Fixtures\Pointcut\Shadow;
use AOP\Tests\Fixtures\Pointcut\StubJoinPoint;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HandlerAdviceExecutionTest extends TestCase
{
    #[Test]
    public function handlerMatchesTheCaughtType(): void
    {
        $handler = Shadow::compile('handler('.DomainError::class.')');

        self::assertInstanceOf(HandlerPointcut::class, $handler);
        self::assertTrue(Shadow::matches($handler, Shadow::handler(DomainError::class)));
        self::assertFalse(Shadow::matches($handler, Shadow::handler(\RuntimeException::class)));
        self::assertFalse(Shadow::matches($handler, Shadow::handler(\Throwable::class)));
    }

    #[Test]
    public function handlerPlusMatchesCatchClausesOfSubtypes(): void
    {
        $handler = Shadow::compile('handler(RuntimeException+)');

        self::assertTrue(Shadow::matches($handler, Shadow::handler(\RuntimeException::class)));
        self::assertTrue(Shadow::matches($handler, Shadow::handler(DomainError::class)), 'DomainError extends RuntimeException');
        self::assertTrue(Shadow::matches($handler, Shadow::handler(\UnexpectedValueException::class)), 'which is a RuntimeException too');
        self::assertFalse(Shadow::matches($handler, Shadow::handler(\LogicException::class)));
        self::assertTrue(Shadow::matches(Shadow::compile('handler(Throwable+)'), Shadow::handler(DomainError::class)));
    }

    #[Test]
    public function handlerWildcards(): void
    {
        self::assertTrue(Shadow::matches(Shadow::compile('handler(AOP\Tests\**)'), Shadow::handler(DomainError::class)));
        self::assertTrue(Shadow::matches(Shadow::compile('handler(**\*Error)'), Shadow::handler(DomainError::class)));
        self::assertFalse(Shadow::matches(Shadow::compile('handler(**\*Exception)'), Shadow::handler(DomainError::class)));
    }

    #[Test]
    public function handlerAppliesOnlyToHandlers(): void
    {
        $handler = Shadow::compile('handler(**)');

        self::assertFalse(Shadow::matches($handler, Shadow::method(Callee::class, 'find')));
        self::assertFalse(Shadow::matches($handler, Shadow::field(Callee::class, 'count')));
        self::assertFalse($handler->matchesMethod(null, new StaticMatchContext(JoinPointKind::EXCEPTION_HANDLER, null)), 'unknown caught type');
        self::assertTrue($handler->matchesClass(null, new StaticMatchContext(JoinPointKind::EXCEPTION_HANDLER, null)), 'a class probe without caught type is not pruned');
    }

    #[Test]
    public function handlerIsStaticAndNotCallerSensitive(): void
    {
        $handler = Shadow::compile('handler(**)');

        self::assertFalse($handler->isRuntime());
        self::assertFalse($handler->isCallerSensitive());
        self::assertSame([], $handler->bindings());
        self::assertTrue(Shadow::compile('handler(**) && within('.Caller::class.')')->isCallerSensitive());
    }

    #[Test]
    public function handlerWithinTheCatchingCode(): void
    {
        $pointcut = Shadow::compile('handler(**) && within('.Caller::class.')');
        $ctx = Shadow::handler(DomainError::class, Shadow::caller(Caller::class, 'run'));

        self::assertTrue(Shadow::matches($pointcut, $ctx));
        self::assertFalse(Shadow::matches($pointcut, Shadow::handler(DomainError::class, Shadow::caller(Callee::class, 'find'))));
    }

    #[Test]
    public function argsBindsTheCaughtException(): void
    {
        $pointcut = Shadow::compile('handler(RuntimeException+) && args(e)', ['e' => \Throwable::class]);
        $error = new DomainError('boom');
        $ctx = Shadow::handler(DomainError::class);
        $runtime = Shadow::runtime(StubJoinPoint::handler($error), $ctx);

        self::assertTrue(Shadow::matches($pointcut, $ctx));
        self::assertTrue($pointcut->isRuntime());
        self::assertSame(['e'], array_keys($pointcut->bindings()));
        self::assertTrue($pointcut->matchesRuntime($runtime));
        self::assertSame($error, $runtime->bound()['e']);
    }

    #[Test]
    public function argsOnAHandlerTakesExactlyOneArgument(): void
    {
        $ctx = Shadow::handler(DomainError::class);

        self::assertFalse(Shadow::matches(Shadow::compile('handler(**) && args()'), $ctx));
        self::assertFalse(Shadow::matches(Shadow::compile('handler(**) && args(a, b)', ['a', 'b']), $ctx));
        self::assertTrue(Shadow::matches(Shadow::compile('handler(**) && args(DomainError)'), $ctx));
    }

    #[Test]
    public function argsTypeIsCheckedAgainstTheException(): void
    {
        $pointcut = Shadow::compile('handler(**) && args('.DomainError::class.')');
        $ctx = Shadow::handler(DomainError::class);

        self::assertTrue($pointcut->matchesRuntime(Shadow::runtime(StubJoinPoint::handler(new DomainError()), $ctx)));
        self::assertFalse($pointcut->matchesRuntime(Shadow::runtime(StubJoinPoint::handler(new \LogicException()), $ctx)));
    }

    #[Test]
    public function negatedHandlerBindsNothing(): void
    {
        $this->expectException(BindingException::class);
        Shadow::compile('!(handler(**) && args(e))', ['e']);
    }

    // adviceexecution

    #[Test]
    public function adviceExecutionMatchesOnlyAdviceExecution(): void
    {
        $pointcut = Shadow::compile('adviceexecution()');

        self::assertInstanceOf(AdviceExecutionPointcut::class, $pointcut);
        self::assertTrue(Shadow::matches($pointcut, Shadow::advice(Conditions::class, 'always')));
        self::assertFalse(Shadow::matches($pointcut, Shadow::method(Conditions::class, 'always')));
        self::assertFalse(Shadow::matches($pointcut, Shadow::method(Conditions::class, 'always', JoinPointKind::METHOD_CALL)));
        self::assertFalse($pointcut->isRuntime());
        self::assertFalse($pointcut->isCallerSensitive());
        self::assertSame([], $pointcut->bindings());
    }

    #[Test]
    public function adviceExecutionNarrowedToAnAspect(): void
    {
        $inConditions = Shadow::compile('adviceexecution() && within('.Conditions::class.')');
        $inCallee = Shadow::compile('adviceexecution() && within('.Callee::class.')');
        $ctx = Shadow::advice(Conditions::class, 'always');

        self::assertTrue(Shadow::matches($inConditions, $ctx));
        self::assertFalse(Shadow::matches($inCallee, $ctx));
        self::assertTrue(Shadow::matches(Shadow::compile('adviceexecution() && withincode(* '.Conditions::class.'::always(..))'), $ctx));
        self::assertTrue(Shadow::matches(Shadow::compile('adviceexecution() && !withincode(* '.Conditions::class.'::never(..))'), $ctx));
    }

    #[Test]
    public function executionAndCallDoNotMatchAdviceExecution(): void
    {
        $ctx = Shadow::advice(Conditions::class, 'always');

        self::assertFalse(Shadow::matches(Shadow::compile('execution(* *(..))'), $ctx));
        self::assertFalse(Shadow::matches(Shadow::compile('call(* *(..))'), $ctx));
    }
}
