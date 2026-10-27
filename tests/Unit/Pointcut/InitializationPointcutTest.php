<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Pointcut;

use AOP\JoinPoint\JoinPointKind;
use AOP\Pointcut\InitializationPointcut;
use AOP\Tests\Fixtures\Pointcut\Callee;
use AOP\Tests\Fixtures\Pointcut\Caller;
use AOP\Tests\Fixtures\Pointcut\NoConstructor;
use AOP\Tests\Fixtures\Pointcut\Shadow;
use AOP\Tests\Fixtures\Pointcut\StubJoinPoint;
use AOP\Tests\Fixtures\Pointcut\SubCallee;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class InitializationPointcutTest extends TestCase
{
    #[Test]
    public function aConstructorPatternMatchesTheConstructorExecution(): void
    {
        $pointcut = Shadow::compile('initialization('.Callee::class.'::__construct(..))');

        self::assertInstanceOf(InitializationPointcut::class, $pointcut);
        self::assertTrue(Shadow::matches($pointcut, Shadow::constructor(Callee::class)));
        self::assertFalse(Shadow::matches($pointcut, Shadow::constructor(Caller::class)));
    }

    #[Test]
    public function theShorthandIsATypePatternForAnyConstructor(): void
    {
        $exact = Shadow::compile('initialization('.Callee::class.')');
        $plus = Shadow::compile('initialization('.Callee::class.'+)');

        self::assertTrue(Shadow::matches($exact, Shadow::constructor(Callee::class)));
        self::assertTrue(Shadow::matches($exact, Shadow::inheritedConstructor(SubCallee::class, Callee::class)), 'Callee::__construct run for a SubCallee');
        self::assertFalse(Shadow::matches($exact, Shadow::constructor(SubCallee::class)), 'without + only the type itself');
        self::assertTrue(Shadow::matches($plus, Shadow::constructor(SubCallee::class)));
        self::assertTrue(Shadow::matches($plus, Shadow::constructor(Callee::class)));
        self::assertFalse(Shadow::matches($plus, Shadow::constructor(Caller::class)));
    }

    #[Test]
    public function parametersAndModifiersNarrowTheConstructor(): void
    {
        $ctx = Shadow::constructor(SubCallee::class);

        self::assertTrue(Shadow::matches(Shadow::compile('initialization('.SubCallee::class.'::__construct(int, string))'), $ctx));
        self::assertTrue(Shadow::matches(Shadow::compile('initialization('.SubCallee::class.'::__construct(int, ..))'), $ctx));
        self::assertFalse(Shadow::matches(Shadow::compile('initialization('.SubCallee::class.'::__construct(int))'), $ctx));
        self::assertFalse(Shadow::matches(Shadow::compile('initialization('.SubCallee::class.'::__construct())'), $ctx));
        self::assertTrue(Shadow::matches(Shadow::compile('initialization(public '.SubCallee::class.'::__construct(..))'), $ctx));
        self::assertFalse(Shadow::matches(Shadow::compile('initialization(protected '.SubCallee::class.'::__construct(..))'), $ctx));
    }

    #[Test]
    public function aClassWithoutConstructorIsInitializedThroughTheInitializationKind(): void
    {
        $ctx = Shadow::initialization(NoConstructor::class);

        self::assertTrue(Shadow::matches(Shadow::compile('initialization('.NoConstructor::class.')'), $ctx));
        self::assertTrue(Shadow::matches(Shadow::compile('initialization('.NoConstructor::class.'::__construct(..))'), $ctx));
        self::assertTrue(Shadow::matches(Shadow::compile('initialization('.NoConstructor::class.'::__construct())'), $ctx));
        self::assertTrue(Shadow::matches(Shadow::compile('initialization(public '.NoConstructor::class.'::__construct(..))'), $ctx));
        self::assertFalse(Shadow::matches(Shadow::compile('initialization('.NoConstructor::class.'::__construct(int))'), $ctx));
        self::assertFalse(Shadow::matches(Shadow::compile('initialization(protected '.NoConstructor::class.'::__construct(..))'), $ctx));
        self::assertFalse(Shadow::matches(Shadow::compile('initialization('.Callee::class.')'), $ctx));
    }

    #[Test]
    public function otherKindsAreNeverInitializations(): void
    {
        $pointcut = Shadow::compile('initialization(**+)');

        self::assertFalse(Shadow::matches($pointcut, Shadow::constructor(Callee::class, JoinPointKind::CONSTRUCTOR_CALL)));
        self::assertFalse(Shadow::matches($pointcut, Shadow::method(Callee::class, 'find')));
        self::assertFalse(Shadow::matches($pointcut, Shadow::field(Callee::class, 'count')));
        self::assertFalse(Shadow::matches(Shadow::compile('execution(**+::__construct(..))'), Shadow::initialization(NoConstructor::class)), 'execution is not initialization');
        self::assertFalse(Shadow::matches(Shadow::compile('call(* **::__construct(..))'), Shadow::initialization(NoConstructor::class)));
    }

    #[Test]
    public function aConstructorExecutionCountsOnlyWhenOutermost(): void
    {
        $pointcut = Shadow::compile('initialization('.Callee::class.'+)');
        $ctx = Shadow::constructor(Callee::class);
        $callee = new Callee();

        self::assertTrue($pointcut->isRuntime());
        self::assertTrue($pointcut->matchesRuntime(Shadow::runtime(StubJoinPoint::constructor($callee, outermost: true), $ctx)));
        self::assertFalse($pointcut->matchesRuntime(Shadow::runtime(StubJoinPoint::constructor($callee, outermost: false), $ctx)), 'parent::__construct()');
    }

    #[Test]
    public function aJoinPointThatDoesNotKnowIsTakenAsOutermost(): void
    {
        $pointcut = Shadow::compile('initialization('.Callee::class.'+)');
        $ctx = Shadow::constructor(Callee::class);
        $plain = new class extends \stdClass {};
        $jp = StubJoinPoint::method(new Callee(), 'find', JoinPointKind::CONSTRUCTOR_EXECUTION);

        self::assertTrue(Shadow::runtime($jp, $ctx)->isOutermost());
        self::assertTrue($pointcut->matchesRuntime(Shadow::runtime($jp, $ctx)));
    }

    #[Test]
    public function theInitializationKindIsNeverFilteredByOutermost(): void
    {
        $pointcut = Shadow::compile('initialization('.NoConstructor::class.')');
        $ctx = Shadow::initialization(NoConstructor::class);
        $jp = new StubJoinPoint(JoinPointKind::INITIALIZATION, StubJoinPoint::constructor(new NoConstructor())->getSignature(), outermost: false);

        self::assertTrue($pointcut->matchesRuntime(Shadow::runtime($jp, $ctx)));
    }

    #[Test]
    public function initializationIsNotCallerSensitiveAndBindsNothing(): void
    {
        $pointcut = Shadow::compile('initialization(**+)');

        self::assertFalse($pointcut->isCallerSensitive());
        self::assertSame([], $pointcut->bindings());
        self::assertTrue(Shadow::compile('initialization(**+) && !this(x)')->isRuntime());
    }

    #[Test]
    public function negatedInitializationAppliesTheOutermostRule(): void
    {
        $pointcut = Shadow::compile('!initialization('.Callee::class.'+)');
        $ctx = Shadow::constructor(Callee::class);
        $callee = new Callee();

        self::assertTrue($pointcut->isRuntime());
        self::assertTrue(Shadow::matches($pointcut, $ctx), 'the static half of a runtime pointcut cannot be inverted');
        self::assertFalse($pointcut->matchesRuntime(Shadow::runtime(StubJoinPoint::constructor($callee, outermost: true), $ctx)));
        self::assertTrue($pointcut->matchesRuntime(Shadow::runtime(StubJoinPoint::constructor($callee, outermost: false), $ctx)));
    }

    #[Test]
    public function aMethodOfAnotherKindIsNeverAnInitialization(): void
    {
        $pointcut = Shadow::compile('initialization(**+)');

        self::assertFalse($pointcut->matchesMethod(new \ReflectionMethod(Callee::class, 'find'), Shadow::method(Callee::class, 'find')));
        self::assertFalse($pointcut->matchesMethod(null, Shadow::method(Callee::class, 'find')));
    }
}
