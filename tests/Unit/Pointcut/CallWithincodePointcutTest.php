<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Pointcut;

use AOP\JoinPoint\JoinPointKind;
use AOP\Pointcut\CallPointcut;
use AOP\Pointcut\WithincodePointcut;
use AOP\Tests\Fixtures\Jp;
use AOP\Tests\Fixtures\Pointcut\Callee;
use AOP\Tests\Fixtures\Pointcut\Caller;
use AOP\Tests\Fixtures\Pointcut\Shadow;
use AOP\Tests\Fixtures\Pointcut\SubCallee;
use AOP\Tests\Fixtures\Pointcut\UnmarkedCaller;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CallWithincodePointcutTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__.'/../../Fixtures/Functions/functions.php';
    }

    #[Test]
    public function callMatchesCallKindsOnly(): void
    {
        $call = Shadow::compile('call(* '.Callee::class.'::find(..))');

        self::assertInstanceOf(CallPointcut::class, $call);
        self::assertTrue(Shadow::matches($call, Shadow::method(Callee::class, 'find', JoinPointKind::METHOD_CALL)));
        self::assertFalse(Shadow::matches($call, Shadow::method(Callee::class, 'find', JoinPointKind::METHOD_EXECUTION)));
        self::assertFalse(Shadow::matches($call, Shadow::field(Callee::class, 'count')));
        self::assertFalse(Shadow::matches($call, Shadow::handler(\RuntimeException::class)));
    }

    #[Test]
    public function executionDoesNotMatchCallKinds(): void
    {
        $execution = Shadow::compile('execution(* '.Callee::class.'::find(..))');

        self::assertTrue(Shadow::matches($execution, Shadow::method(Callee::class, 'find')));
        self::assertFalse(Shadow::matches($execution, Shadow::method(Callee::class, 'find', JoinPointKind::METHOD_CALL)));
    }

    #[Test]
    public function callPatternsDescribeTheCalleeLikeExecution(): void
    {
        $static = Shadow::method(Callee::class, 'make', JoinPointKind::METHOD_CALL);

        self::assertTrue(Shadow::matches(Shadow::compile('call(public * '.Callee::class.'::make())'), $static));
        self::assertFalse(Shadow::matches(Shadow::compile('call(protected * '.Callee::class.'::make())'), $static));
        self::assertFalse(Shadow::matches(Shadow::compile('call(* '.Callee::class.'::make(int))'), $static));
        self::assertFalse(Shadow::matches(Shadow::compile('call(int '.Callee::class.'::find(int))'), Shadow::method(Callee::class, 'find', JoinPointKind::METHOD_CALL)));
        self::assertTrue(Shadow::matches(Shadow::compile('call(string '.Callee::class.'::find(int))'), Shadow::method(Callee::class, 'find', JoinPointKind::METHOD_CALL)));
    }

    #[Test]
    public function callOnASubclassMatchesTheDeclaringTypeAndItsSubtypes(): void
    {
        $onSub = Shadow::method(SubCallee::class, 'find', JoinPointKind::METHOD_CALL);

        self::assertTrue(Shadow::matches(Shadow::compile('call(* '.Callee::class.'::find(..))'), $onSub), 'declared in Callee');
        self::assertTrue(Shadow::matches(Shadow::compile('call(* '.Callee::class.'+::find(..))'), $onSub));
        self::assertTrue(Shadow::matches(Shadow::compile('call(* '.SubCallee::class.'::find(..))'), $onSub), 'the runtime class');
        self::assertFalse(Shadow::matches(Shadow::compile('call(* '.Caller::class.'::find(..))'), $onSub));
    }

    #[Test]
    public function callMatchesConstructorsAndFunctions(): void
    {
        self::assertFalse(Shadow::matches(Shadow::compile('call('.Callee::class.'+::__construct(int))'), Shadow::constructor(SubCallee::class, JoinPointKind::CONSTRUCTOR_CALL)), 'SubCallee::__construct takes (int, string)');
        self::assertTrue(Shadow::matches(Shadow::compile('call('.Callee::class.'+::__construct(int, ..))'), Shadow::constructor(SubCallee::class, JoinPointKind::CONSTRUCTOR_CALL)));
        self::assertTrue(Shadow::matches(Shadow::compile('call(* AOP\Tests\Fixtures\Functions\f*(string))'), Shadow::function('AOP\Tests\Fixtures\Functions\fmt', JoinPointKind::FUNCTION_CALL)));
        self::assertFalse(Shadow::matches(Shadow::compile('call(* AOP\Tests\Fixtures\Functions\f*(int))'), Shadow::function('AOP\Tests\Fixtures\Functions\fmt', JoinPointKind::FUNCTION_CALL)));
    }

    #[Test]
    public function callAloneIsNotCallerSensitiveButCombinedWithACallerPredicateIs(): void
    {
        $call = Shadow::compile('call(* '.Callee::class.'::find(..))');
        $combined = Shadow::compile('call(* '.Callee::class.'::find(..)) && within('.Caller::class.')');

        self::assertFalse($call->isCallerSensitive());
        self::assertFalse($call->isRuntime());
        self::assertSame([], $call->bindings());
        self::assertTrue($combined->isCallerSensitive());
        self::assertTrue(Shadow::compile('call(* *(..)) && withincode(* *(..))')->isCallerSensitive());
        self::assertTrue(Shadow::compile('call(* *(..)) && !this(x)')->isCallerSensitive());
    }

    #[Test]
    public function callRestrictedToACaller(): void
    {
        $pointcut = Shadow::compile('call(* '.Callee::class.'::find(..)) && within('.Caller::class.')');

        self::assertTrue(Shadow::matches($pointcut, Shadow::method(Callee::class, 'find', JoinPointKind::METHOD_CALL, Shadow::caller(Caller::class, 'run'))));
        self::assertFalse(Shadow::matches($pointcut, Shadow::method(Callee::class, 'find', JoinPointKind::METHOD_CALL, Shadow::caller(UnmarkedCaller::class, 'run'))));
        self::assertFalse(Shadow::matches($pointcut, Shadow::method(Callee::class, 'find', JoinPointKind::METHOD_CALL)));
    }

    // withincode

    #[Test]
    public function withincodeUsesTheExecutingFunctionForExecutionKinds(): void
    {
        $pointcut = Shadow::compile('withincode(* '.Callee::class.'::find(..))');

        self::assertInstanceOf(WithincodePointcut::class, $pointcut);
        self::assertTrue(Shadow::matches($pointcut, Shadow::method(Callee::class, 'find')));
        self::assertTrue(Shadow::matches($pointcut, Shadow::method(SubCallee::class, 'find')), 'inherited: find() is declared in Callee');
        self::assertFalse(Shadow::matches($pointcut, Shadow::method(Callee::class, 'make')));
        self::assertTrue(Shadow::matches(Shadow::compile('withincode('.Callee::class.'::__construct(..))'), Shadow::constructor(Callee::class)));
        self::assertTrue(Shadow::matches(Shadow::compile('withincode(* AOP\Tests\Fixtures\Functions\fmt(..))'), Shadow::function('AOP\Tests\Fixtures\Functions\fmt')));
    }

    #[Test]
    public function withincodeUsesTheCallerFunctionForEverythingElse(): void
    {
        $pointcut = Shadow::compile('withincode(* '.Caller::class.'::run(..))');
        $inRun = Shadow::caller(Caller::class, 'run');
        $inTagged = Shadow::caller(Caller::class, 'tagged');

        self::assertTrue(Shadow::matches($pointcut, Shadow::method(Callee::class, 'find', JoinPointKind::METHOD_CALL, $inRun)));
        self::assertFalse(Shadow::matches($pointcut, Shadow::method(Callee::class, 'find', JoinPointKind::METHOD_CALL, $inTagged)));
        self::assertTrue(Shadow::matches($pointcut, Shadow::field(Callee::class, 'count', JoinPointKind::FIELD_GET, $inRun)));
        self::assertTrue(Shadow::matches($pointcut, Shadow::field(Callee::class, 'count', JoinPointKind::FIELD_SET, $inRun)));
        self::assertFalse(Shadow::matches($pointcut, Shadow::field(Callee::class, 'count', JoinPointKind::FIELD_SET, $inTagged)));
        self::assertTrue(Shadow::matches($pointcut, Shadow::handler(\RuntimeException::class, $inRun)));
        self::assertFalse(Shadow::matches($pointcut, Shadow::handler(\RuntimeException::class)), 'unknown caller');
        self::assertFalse(Shadow::matches($pointcut, Shadow::method(Callee::class, 'find', JoinPointKind::METHOD_CALL, Shadow::caller(Caller::class))), 'caller without a function (top-level code)');
    }

    #[Test]
    public function withincodeInsideAFunctionAndAConstructor(): void
    {
        $inFunction = Shadow::functionCaller('AOP\Tests\Fixtures\Functions\fmt');

        self::assertTrue(Shadow::matches(Shadow::compile('withincode(* AOP\Tests\Fixtures\Functions\fmt(..))'), Shadow::method(Callee::class, 'find', JoinPointKind::METHOD_CALL, $inFunction)));
        self::assertFalse(Shadow::matches(Shadow::compile('withincode(* '.Caller::class.'::*(..))'), Shadow::method(Callee::class, 'find', JoinPointKind::METHOD_CALL, $inFunction)));
        self::assertTrue(Shadow::matches(Shadow::compile('withincode('.Caller::class.'::__construct(..))'), Shadow::constructor(Callee::class, JoinPointKind::CONSTRUCTOR_CALL, Shadow::caller(Caller::class, '__construct'))));
    }

    #[Test]
    public function withincodeHonoursModifiersAndStaticCallers(): void
    {
        $guarded = Shadow::method(Callee::class, 'find', JoinPointKind::METHOD_CALL, Shadow::caller(Caller::class, 'guarded'));
        $static = Shadow::method(Callee::class, 'find', JoinPointKind::METHOD_CALL, Shadow::caller(Caller::class, 'staticRun'));

        self::assertTrue(Shadow::matches(Shadow::compile('withincode(protected * **::guarded(..))'), $guarded));
        self::assertFalse(Shadow::matches(Shadow::compile('withincode(public * **::guarded(..))'), $guarded));
        self::assertTrue(Shadow::matches(Shadow::compile('withincode(* **::staticRun(..))'), $static));
    }

    #[Test]
    public function withincodeOnInitializationOfAClassWithoutConstructorUsesTheImplicitConstructor(): void
    {
        $ctx = Shadow::initialization(\AOP\Tests\Fixtures\Pointcut\NoConstructor::class);

        self::assertTrue(Shadow::matches(Shadow::compile('withincode(**::__construct(..))'), $ctx));
        self::assertFalse(Shadow::matches(Shadow::compile('withincode(protected **::__construct(..))'), $ctx));
        self::assertFalse(Shadow::matches(Shadow::compile('withincode(* **::find(..))'), $ctx));
    }

    #[Test]
    public function withincodeIsCallerSensitiveAndStatic(): void
    {
        $pointcut = Shadow::compile('withincode(* *(..))');

        self::assertTrue($pointcut->isCallerSensitive());
        self::assertFalse($pointcut->isRuntime());
        self::assertSame([], $pointcut->bindings());
        self::assertTrue(Shadow::compile('execution(* *(..)) && !withincode(* *(..))')->isCallerSensitive());
    }

    #[Test]
    public function callNeverMatchesAMissingFunctionOrTheWrongFunctionKind(): void
    {
        $call = Shadow::compile('call(* '.Callee::class.'::find(..))');

        self::assertFalse($call->matchesMethod(null, Shadow::method(Callee::class, 'find', JoinPointKind::METHOD_CALL)));
        self::assertFalse($call->matchesMethod(new \ReflectionMethod(Callee::class, 'find'), Shadow::method(Callee::class, 'find')), 'an execution kind');
        self::assertFalse($call->matchesMethod(new \ReflectionFunction('strlen'), Shadow::function('strlen', JoinPointKind::FUNCTION_CALL)), 'a function is not a method call');
        self::assertFalse($call->matchesMethod(new \ReflectionMethod(Callee::class, 'find'), Shadow::function('strlen', JoinPointKind::FUNCTION_CALL)), 'a method is not a function call');
    }

    #[Test]
    public function callIsStaticNotCallerSensitiveAndBindsNothing(): void
    {
        $call = Shadow::compile('call(* '.Callee::class.'::find(..))');

        self::assertFalse($call->isRuntime());
        self::assertFalse($call->isCallerSensitive());
        self::assertSame([], $call->bindings());
        self::assertTrue($call->matchesRuntime(Jp::runtime(new Callee(), 'find', [1])));
    }

    #[Test]
    public function aPatternWithADeclaringTypeNeverMatchesAFunction(): void
    {
        $execution = Shadow::compile('execution(* '.Callee::class.'::*(..))');

        self::assertFalse($execution->matchesMethod(new \ReflectionFunction('strlen'), Shadow::function('strlen')));
    }
}
