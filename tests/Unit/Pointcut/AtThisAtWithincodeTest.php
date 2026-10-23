<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Pointcut;

use AOP\JoinPoint\JoinPointKind;
use AOP\Pointcut\AtThisPointcut;
use AOP\Pointcut\AtWithincodePointcut;
use AOP\Pointcut\BindingKind;
use AOP\Tests\Fixtures\Hierarchy\Marker;
use AOP\Tests\Fixtures\Hierarchy\Plain;
use AOP\Tests\Fixtures\Pointcut\Callee;
use AOP\Tests\Fixtures\Pointcut\Caller;
use AOP\Tests\Fixtures\Pointcut\Shadow;
use AOP\Tests\Fixtures\Pointcut\StubJoinPoint;
use AOP\Tests\Fixtures\Pointcut\UnmarkedCaller;
use AOP\Tests\Fixtures\Targets\PropertyTag;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AtThisAtWithincodeTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__.'/../../Fixtures/Functions/functions.php';
    }

    // @this

    #[Test]
    public function atThisLooksAtTheClassOfTheExecutingObject(): void
    {
        $pointcut = Shadow::compile('@this('.Marker::class.')');

        self::assertInstanceOf(AtThisPointcut::class, $pointcut);
        self::assertTrue(Shadow::matches($pointcut, Shadow::method(Callee::class, 'find')), 'Callee is #[Marker]');
        self::assertFalse(Shadow::matches($pointcut, Shadow::method(UnmarkedCaller::class, 'run')));
        self::assertFalse(Shadow::matches($pointcut, Shadow::method(Callee::class, 'make')), 'static: no this');
        self::assertFalse(Shadow::matches($pointcut, Shadow::function('AOP\Tests\Fixtures\Functions\fmt')));
        self::assertTrue(Shadow::matches($pointcut, Shadow::constructor(Callee::class)));
        self::assertTrue(Shadow::matches($pointcut, Shadow::initialization(Callee::class)));
        self::assertFalse(Shadow::matches($pointcut, Shadow::initialization(Plain::class)));
    }

    #[Test]
    public function atThisLooksAtTheCallerForCallsFieldsAndHandlers(): void
    {
        $pointcut = Shadow::compile('@this('.Marker::class.')');
        $marked = Shadow::caller(Caller::class, 'run');
        $unmarked = Shadow::caller(UnmarkedCaller::class, 'run');
        $static = Shadow::caller(Caller::class, 'staticRun');

        foreach ([JoinPointKind::METHOD_CALL, JoinPointKind::CONSTRUCTOR_CALL] as $kind) {
            self::assertTrue(Shadow::matches($pointcut, Shadow::method(UnmarkedCaller::class, 'run', $kind, $marked)), $kind->value);
            self::assertFalse(Shadow::matches($pointcut, Shadow::method(UnmarkedCaller::class, 'run', $kind, $unmarked)), $kind->value);
            self::assertFalse(Shadow::matches($pointcut, Shadow::method(UnmarkedCaller::class, 'run', $kind, $static)), $kind->value.' from static code');
            self::assertFalse(Shadow::matches($pointcut, Shadow::method(UnmarkedCaller::class, 'run', $kind)), $kind->value.' from an unknown caller');
        }
        self::assertTrue(Shadow::matches($pointcut, Shadow::field(Callee::class, 'count', JoinPointKind::FIELD_SET, $marked)));
        self::assertFalse(Shadow::matches($pointcut, Shadow::field(Callee::class, 'count', JoinPointKind::FIELD_SET, $unmarked)));
        self::assertTrue(Shadow::matches($pointcut, Shadow::handler(\RuntimeException::class, $marked)));
        self::assertFalse(Shadow::matches($pointcut, Shadow::function('AOP\Tests\Fixtures\Functions\fmt', JoinPointKind::FUNCTION_CALL, Shadow::functionCaller('AOP\Tests\Fixtures\Functions\fmt'))), 'a function has no this');
    }

    #[Test]
    public function atThisIsStaticWithoutABindingAndCallerSensitive(): void
    {
        $pointcut = Shadow::compile('@this('.Marker::class.')');

        self::assertFalse($pointcut->isRuntime());
        self::assertSame([], $pointcut->bindings());
        self::assertTrue($pointcut->isCallerSensitive());
    }

    #[Test]
    public function atThisBindsTheAttributeOfTheExecutingObject(): void
    {
        $pointcut = Shadow::compile('@this(m)', ['m' => Marker::class]);
        $ctx = Shadow::method(Callee::class, 'find');
        $runtime = Shadow::runtime(StubJoinPoint::method(new Callee(), 'find'), $ctx);

        self::assertTrue($pointcut->isRuntime());
        self::assertSame(['m'], array_keys($pointcut->bindings()));
        self::assertSame(BindingKind::AT_THIS, $pointcut->bindings()['m']->kind);
        self::assertTrue(Shadow::matches($pointcut, $ctx));
        self::assertTrue($pointcut->matchesRuntime($runtime));
        $marker = $runtime->bound()['m'];
        self::assertInstanceOf(Marker::class, $marker);
        self::assertSame('callee', $marker->label);
    }

    #[Test]
    public function atThisBindingFiltersStaticallyByTheTypeRestriction(): void
    {
        $typed = Shadow::compile('@this(m)', ['m' => Marker::class]);
        $other = Shadow::compile('@this(m)', ['m' => PropertyTag::class]);

        self::assertTrue(Shadow::matches($typed, Shadow::method(Callee::class, 'find')));
        self::assertFalse(Shadow::matches($other, Shadow::method(Callee::class, 'find')));
        self::assertFalse(Shadow::matches($typed, Shadow::method(UnmarkedCaller::class, 'run')));
    }

    #[Test]
    public function atThisBindingFailsWithoutAnObject(): void
    {
        $pointcut = Shadow::compile('@this(m)', ['m' => Marker::class]);
        $ctx = Shadow::method(Callee::class, 'make');
        $runtime = Shadow::runtime(StubJoinPoint::method(new Callee(), 'make'), $ctx);

        self::assertFalse($pointcut->matchesRuntime($runtime));
        self::assertSame([], $runtime->bound());
    }

    #[Test]
    public function atThisBindsTheCallersAttributeForCalls(): void
    {
        $pointcut = Shadow::compile('@this(m)', ['m' => Marker::class]);
        $ctx = Shadow::method(UnmarkedCaller::class, 'run', JoinPointKind::METHOD_CALL, Shadow::caller(Caller::class, 'run'));
        $runtime = Shadow::runtime(StubJoinPoint::method(new UnmarkedCaller(), 'run', JoinPointKind::METHOD_CALL, [], new Caller()), $ctx);

        self::assertTrue(Shadow::matches($pointcut, $ctx));
        self::assertTrue($pointcut->matchesRuntime($runtime));
        $marker = $runtime->bound()['m'];
        self::assertInstanceOf(Marker::class, $marker);
        self::assertSame('caller', $marker->label);
    }

    // @withincode

    #[Test]
    public function atWithincodeLooksAtTheExecutingFunctionForExecutionKinds(): void
    {
        $pointcut = Shadow::compile('@withincode('.PropertyTag::class.')');

        self::assertInstanceOf(AtWithincodePointcut::class, $pointcut);
        self::assertTrue(Shadow::matches($pointcut, Shadow::method(Caller::class, 'tagged')));
        self::assertFalse(Shadow::matches($pointcut, Shadow::method(Caller::class, 'run')));
        self::assertTrue(Shadow::matches($pointcut, Shadow::function('AOP\Tests\Fixtures\Functions\tagged')));
        self::assertFalse(Shadow::matches($pointcut, Shadow::function('AOP\Tests\Fixtures\Functions\noop')));
    }

    #[Test]
    public function atWithincodeLooksAtTheCallerFunctionForEverythingElse(): void
    {
        $pointcut = Shadow::compile('@withincode('.PropertyTag::class.')');
        $inTagged = Shadow::caller(Caller::class, 'tagged');
        $inRun = Shadow::caller(Caller::class, 'run');

        self::assertTrue(Shadow::matches($pointcut, Shadow::method(Callee::class, 'find', JoinPointKind::METHOD_CALL, $inTagged)));
        self::assertFalse(Shadow::matches($pointcut, Shadow::method(Callee::class, 'find', JoinPointKind::METHOD_CALL, $inRun)));
        self::assertFalse(Shadow::matches($pointcut, Shadow::method(Callee::class, 'find', JoinPointKind::METHOD_CALL)), 'unknown caller');
        self::assertTrue(Shadow::matches($pointcut, Shadow::field(Callee::class, 'count', JoinPointKind::FIELD_GET, $inTagged)));
        self::assertTrue(Shadow::matches($pointcut, Shadow::handler(\RuntimeException::class, $inTagged)));
        self::assertTrue(Shadow::matches($pointcut, Shadow::method(Callee::class, 'find', JoinPointKind::METHOD_CALL, Shadow::functionCaller('AOP\Tests\Fixtures\Functions\tagged'))));
    }

    #[Test]
    public function atWithincodeIsStaticWithoutABindingAndCallerSensitive(): void
    {
        $pointcut = Shadow::compile('@withincode('.PropertyTag::class.')');

        self::assertFalse($pointcut->isRuntime());
        self::assertSame([], $pointcut->bindings());
        self::assertTrue($pointcut->isCallerSensitive());
    }

    #[Test]
    public function atWithincodeBindsTheAttributeInstance(): void
    {
        $pointcut = Shadow::compile('@withincode(tag)', ['tag' => PropertyTag::class]);

        self::assertTrue($pointcut->isRuntime());
        self::assertSame(BindingKind::AT_WITHINCODE, $pointcut->bindings()['tag']->kind);

        $call = Shadow::method(Callee::class, 'find', JoinPointKind::METHOD_CALL, Shadow::caller(Caller::class, 'tagged'));
        $callRuntime = Shadow::runtime(StubJoinPoint::method(new Callee(), 'find', JoinPointKind::METHOD_CALL), $call);
        self::assertTrue(Shadow::matches($pointcut, $call));
        self::assertTrue($pointcut->matchesRuntime($callRuntime));
        $tag = $callRuntime->bound()['tag'];
        self::assertInstanceOf(PropertyTag::class, $tag);
        self::assertSame('tagged', $tag->name);

        $execution = Shadow::method(Caller::class, 'tagged');
        $executionRuntime = Shadow::runtime(StubJoinPoint::method(new Caller(), 'tagged'), $execution);
        self::assertTrue($pointcut->matchesRuntime($executionRuntime));
        $tag = $executionRuntime->bound()['tag'];
        self::assertInstanceOf(PropertyTag::class, $tag);
        self::assertSame('tagged', $tag->name);
    }

    #[Test]
    public function atWithincodeBindingFailsWithoutAnEnclosingFunction(): void
    {
        $pointcut = Shadow::compile('@withincode(tag)', ['tag']);
        $ctx = Shadow::method(Callee::class, 'find', JoinPointKind::METHOD_CALL);
        $runtime = Shadow::runtime(StubJoinPoint::method(new Callee(), 'find', JoinPointKind::METHOD_CALL), $ctx);

        self::assertFalse(Shadow::matches($pointcut, $ctx));
        self::assertFalse($pointcut->matchesRuntime($runtime));
    }

    #[Test]
    public function anUntypedBindingTakesTheFirstAttribute(): void
    {
        $pointcut = Shadow::compile('@withincode(any)', ['any']);
        $ctx = Shadow::method(Caller::class, 'tagged');
        $runtime = Shadow::runtime(StubJoinPoint::method(new Caller(), 'tagged'), $ctx);

        self::assertTrue($pointcut->matchesRuntime($runtime));
        self::assertInstanceOf(PropertyTag::class, $runtime->bound()['any']);
    }
}
