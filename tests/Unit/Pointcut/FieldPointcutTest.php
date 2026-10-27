<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Pointcut;

use AOP\Exception\BindingException;
use AOP\JoinPoint\JoinPointKind;
use AOP\Pointcut\GetPointcut;
use AOP\Pointcut\Pattern\FieldPattern;
use AOP\Pointcut\Pattern\NamePattern;
use AOP\Pointcut\Pattern\TypePattern;
use AOP\Pointcut\SetPointcut;
use AOP\Tests\Fixtures\Pointcut\Callee;
use AOP\Tests\Fixtures\Pointcut\Caller;
use AOP\Tests\Fixtures\Pointcut\Shadow;
use AOP\Tests\Fixtures\Pointcut\StubJoinPoint;
use AOP\Tests\Fixtures\Pointcut\SubCallee;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FieldPointcutTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, bool}> pattern (with $T for the Callee class), property, expected
     */
    public static function patterns(): iterable
    {
        yield 'typed' => ['int $T::$count', 'count', true];
        yield 'wrong type' => ['string $T::$count', 'count', false];
        yield 'any type' => ['* $T::$count', 'count', true];
        yield 'no type' => ['$T::$count', 'count', true];
        yield 'any type matches an untyped property' => ['* $T::$untyped', 'untyped', true];
        yield 'a type never matches an untyped property' => ['int $T::$untyped', 'untyped', false];
        yield 'name wildcard' => ['* $T::$c*', 'count', true];
        yield 'name wildcard miss' => ['* $T::$x*', 'count', false];
        yield 'all names' => ['* $T::$*', 'label', true];
        yield 'nullable type' => ['public ?string $T::$label', 'label', true];
        yield 'plain type does not match a nullable property' => ['string $T::$label', 'label', false];
        yield 'public' => ['public * $T::$count', 'count', true];
        yield 'public misses protected' => ['public * $T::$secret', 'secret', false];
        yield 'protected' => ['protected string $T::$secret', 'secret', true];
        yield 'private static' => ['private static int $T::$total', 'total', true];
        yield 'static misses instance' => ['static * $T::$count', 'count', false];
        yield 'readonly' => ['public readonly int $T::$fixed', 'fixed', true];
        yield 'readonly misses writable' => ['readonly * $T::$count', 'count', false];
        yield 'subtypes declaring type' => ['int $T+::$count', 'count', true];
        yield 'namespace wildcard' => ['* AOP\Tests\Fixtures\**::$*', 'count', true];
        yield 'other namespace' => ['* Other\**::$*', 'count', false];
    }

    #[Test]
    #[DataProvider('patterns')]
    public function getAndSetUseTheSamePattern(string $pattern, string $property, bool $expected): void
    {
        $pattern = str_replace('$T', Callee::class, $pattern);

        foreach (['get' => JoinPointKind::FIELD_GET, 'set' => JoinPointKind::FIELD_SET] as $designator => $kind) {
            $pointcut = Shadow::compile($designator.'('.$pattern.')');

            self::assertSame($expected, Shadow::matches($pointcut, Shadow::field(Callee::class, $property, $kind)), $designator.'('.$pattern.')');
        }
    }

    #[Test]
    public function getMatchesReadsAndSetMatchesWrites(): void
    {
        $get = Shadow::compile('get(* '.Callee::class.'::$count)');
        $set = Shadow::compile('set(* '.Callee::class.'::$count)');
        $read = Shadow::field(Callee::class, 'count', JoinPointKind::FIELD_GET);
        $write = Shadow::field(Callee::class, 'count', JoinPointKind::FIELD_SET);

        self::assertInstanceOf(GetPointcut::class, $get);
        self::assertInstanceOf(SetPointcut::class, $set);
        self::assertTrue(Shadow::matches($get, $read));
        self::assertFalse(Shadow::matches($get, $write));
        self::assertTrue(Shadow::matches($set, $write));
        self::assertFalse(Shadow::matches($set, $read));
    }

    #[Test]
    public function fieldDesignatorsNeverMatchOtherKinds(): void
    {
        $get = Shadow::compile('get(* **::$*)');

        self::assertFalse(Shadow::matches($get, Shadow::method(Callee::class, 'find')));
        self::assertFalse(Shadow::matches($get, Shadow::method(Callee::class, 'find', JoinPointKind::METHOD_CALL)));
        self::assertFalse(Shadow::matches($get, Shadow::handler(\RuntimeException::class)));
        self::assertFalse(Shadow::matches($get, Shadow::function('strlen')));
        self::assertFalse($get->matchesMethod(null, Shadow::method(Callee::class, 'find')), 'a field kind without a property');
    }

    #[Test]
    public function theDeclaringTypeMatchesTheRuntimeClassOrTheClassDeclaringTheProperty(): void
    {
        $onSub = Shadow::field(Callee::class, 'count', JoinPointKind::FIELD_GET, runtimeClass: SubCallee::class);

        self::assertTrue(Shadow::matches(Shadow::compile('get(* '.SubCallee::class.'::$count)'), $onSub), 'the runtime class');
        self::assertTrue(Shadow::matches(Shadow::compile('get(* '.Callee::class.'::$count)'), $onSub), 'the declaring class');
        self::assertTrue(Shadow::matches(Shadow::compile('get(* '.Callee::class.'+::$count)'), $onSub));
        self::assertFalse(Shadow::matches(Shadow::compile('get(* '.Caller::class.'::$count)'), $onSub));
        self::assertFalse(Shadow::matches(Shadow::compile('get(* '.Caller::class.'+::$count)'), $onSub));
    }

    #[Test]
    public function theClassPrefilterAcceptsBothFieldKindsForClassProbes(): void
    {
        $set = Shadow::compile('set(* '.Callee::class.'+::$*)');
        $probe = Shadow::field(Callee::class, 'count', JoinPointKind::FIELD_GET);

        self::assertTrue($set->matchesClass($probe->targetClass, $probe), 'the engine probes with field-get');
        self::assertTrue($set->matchesClass(new \ReflectionClass(SubCallee::class), $probe));
        self::assertFalse($set->matchesClass(new \ReflectionClass(Caller::class), $probe));
        self::assertFalse($set->matchesClass(null, $probe));
        self::assertFalse($set->matchesClass($probe->targetClass, Shadow::method(Callee::class, 'find')));
    }

    #[Test]
    public function fieldDesignatorsAreStaticAndNotCallerSensitive(): void
    {
        foreach (['get(* **::$*)', 'set(* **::$*)'] as $expression) {
            $pointcut = Shadow::compile($expression);

            self::assertFalse($pointcut->isRuntime(), $expression);
            self::assertFalse($pointcut->isCallerSensitive(), $expression);
            self::assertSame([], $pointcut->bindings(), $expression);
        }
        self::assertTrue(Shadow::compile('set(* **::$*) && within('.Caller::class.')')->isCallerSensitive());
    }

    #[Test]
    public function setArgsAreTheNewValue(): void
    {
        $pointcut = Shadow::compile('set(* '.Callee::class.'::$count) && args(value)', ['value' => 'int']);
        $callee = new Callee();
        $ctx = Shadow::field(Callee::class, 'count', JoinPointKind::FIELD_SET);
        $runtime = Shadow::runtime(StubJoinPoint::field($callee, 'count', JoinPointKind::FIELD_SET, 42), $ctx);

        self::assertTrue(Shadow::matches($pointcut, $ctx));
        self::assertTrue($pointcut->isRuntime());
        self::assertSame(['value'], array_keys($pointcut->bindings()));
        self::assertTrue($pointcut->matchesRuntime($runtime));
        self::assertSame(['value' => 42], $runtime->bound());
    }

    #[Test]
    public function setWithArgsOfAnIncompatibleTypeIsPrunedStatically(): void
    {
        $ctx = Shadow::field(Callee::class, 'count', JoinPointKind::FIELD_SET);

        self::assertFalse(Shadow::matches(Shadow::compile('set(* '.Callee::class.'::$count) && args(string)'), $ctx));
        self::assertTrue(Shadow::matches(Shadow::compile('set(* '.Callee::class.'::$count) && args(int)'), $ctx));
        self::assertFalse(Shadow::matches(Shadow::compile('set(* '.Callee::class.'::$count) && args(int, int)'), $ctx), 'a set has one argument');
    }

    #[Test]
    public function getHasNoArguments(): void
    {
        $ctx = Shadow::field(Callee::class, 'count', JoinPointKind::FIELD_GET);

        self::assertTrue(Shadow::matches(Shadow::compile('get(* '.Callee::class.'::$count) && args()'), $ctx));
        self::assertFalse(Shadow::matches(Shadow::compile('get(* '.Callee::class.'::$count) && args(x)', ['x']), $ctx));
    }

    #[Test]
    public function getBindsTheTargetAndTheCallerThroughThisAndTarget(): void
    {
        $pointcut = Shadow::compile('get(* '.Callee::class.'::$count) && target(callee) && this(caller)', ['callee' => Callee::class, 'caller' => Caller::class]);
        $callee = new Callee();
        $caller = new Caller();
        $jp = new StubJoinPoint(JoinPointKind::FIELD_GET, StubJoinPoint::field($callee, 'count')->getSignature(), $caller, $callee);
        $ctx = Shadow::field(Callee::class, 'count', JoinPointKind::FIELD_GET, Shadow::caller(Caller::class, 'run'));
        $runtime = Shadow::runtime($jp, $ctx);

        self::assertTrue(Shadow::matches($pointcut, $ctx));
        self::assertTrue($pointcut->matchesRuntime($runtime));
        self::assertSame($callee, $runtime->bound()['callee']);
        self::assertSame($caller, $runtime->bound()['caller']);
        self::assertTrue($pointcut->isCallerSensitive(), 'this() reads the caller');
    }

    #[Test]
    public function aStaticPropertyHasNoTarget(): void
    {
        $ctx = Shadow::field(Callee::class, 'total', JoinPointKind::FIELD_GET);

        self::assertTrue(Shadow::matches(Shadow::compile('get(* '.Callee::class.'::$total)'), $ctx));
        self::assertFalse(Shadow::matches(Shadow::compile('get(* '.Callee::class.'::$total) && target('.Callee::class.')'), $ctx));
    }

    #[Test]
    public function orBranchesOfFieldPointcutsMustBindTheSameNames(): void
    {
        $or = Shadow::compile('(set(* '.Callee::class.'::$count) && args(v)) || (set(* '.Callee::class.'::$label) && args(v))', ['v']);

        self::assertSame(['v'], array_keys($or->bindings()));

        $this->expectException(BindingException::class);
        Shadow::compile('(set(* '.Callee::class.'::$count) && args(v)) || get(* '.Callee::class.'::$label)', ['v']);
    }

    #[Test]
    public function negatedFieldPointcutsCannotBind(): void
    {
        $this->expectException(BindingException::class);
        Shadow::compile('!(set(* '.Callee::class.'::$count) && args(v))', ['v']);
    }

    #[Test]
    public function aFieldPatternRejectsUnknownModifiers(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown modifier "final"');

        new FieldPattern(['public', '!final'], null, null, new NamePattern('*'));
    }

    #[Test]
    public function aFieldPatternMatchesTheDeclaringTypeAgainstTheWholeHierarchy(): void
    {
        $any = new FieldPattern([], null, null, new NamePattern('*'));
        $countable = new FieldPattern([], null, new TypePattern(\Countable::class, false, false), new NamePattern('*'));
        $parent = new FieldPattern([], null, new TypePattern(Callee::class, false, false), new NamePattern('*'));

        self::assertTrue($any->matchesClass(null), 'no declaring type restriction accepts even an unknown class');
        self::assertFalse($countable->matchesClass(null));
        self::assertTrue($countable->matchesClass(new \ReflectionClass(\ArrayObject::class)), 'an implemented interface');
        self::assertFalse($countable->matchesClass(new \ReflectionClass(\stdClass::class)));
        self::assertTrue($parent->matchesClass(new \ReflectionClass(SubCallee::class)), 'a parent class');
    }
}
