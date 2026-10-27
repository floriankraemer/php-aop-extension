<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Pointcut;

use AOP\Tests\Fixtures\Hierarchy\Marker;
use AOP\Tests\Fixtures\Jp;
use AOP\Tests\Fixtures\Pointcut\Callee;
use AOP\Tests\Fixtures\Pointcut\Conditions;
use AOP\Tests\Fixtures\Pointcut\Shadow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * isRuntime(), isCallerSensitive() and bindings() of every designator and of the combinators over them.
 */
final class DesignatorPropertiesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool, bool, list<string>}> expression, runtime, caller-sensitive, binding names
     */
    public static function expressions(): iterable
    {
        $always = 'if('.Conditions::class.'::always())';

        yield 'execution' => ['execution(* *(..))', false, false, []];
        yield 'call' => ['call(* *(..))', false, false, []];
        yield 'withincode' => ['withincode(* *(..))', false, true, []];
        yield '@withincode type' => ['@withincode(Foo)', false, true, []];
        yield '@withincode binding' => ['@withincode(a)', true, true, ['a']];
        yield '@this type' => ['@this(Foo)', false, true, []];
        yield '@this binding' => ['@this(a)', true, true, ['a']];
        yield 'cflow' => ['cflow(execution(* *(..)))', true, false, []];
        yield 'cflowbelow' => ['cflowbelow(call(* *(..)))', true, false, []];
        yield 'cflow with binding' => ['cflow(this(a))', true, false, ['a']];
        yield 'cflow with caller predicate (the entries carry their own caller)' => ['cflow(within(Foo))', true, false, []];
        yield 'if' => [$always, true, false, []];
        yield 'initialization' => ['initialization(Foo+)', true, false, []];
        yield 'adviceexecution' => ['adviceexecution()', false, false, []];
        yield 'handler' => ['handler(Foo)', false, false, []];
        yield 'handler with args binding' => ['handler(Foo) && args(a)', true, false, ['a']];
        yield 'get' => ['get(* Foo::$x)', false, false, []];
        yield 'set with args binding' => ['set(* Foo::$x) && args(a)', true, false, ['a']];
        yield 'service' => ['service(foo)', false, false, []];
        yield 'within' => ['within(Foo)', false, true, []];
        yield 'this type' => ['this(Foo)', false, true, []];
        yield 'target binding' => ['target(a)', true, false, ['a']];
        yield 'and' => ['execution(* *(..)) && call(* *(..))', false, false, []];
        yield 'and with a runtime child' => ['execution(* *(..)) && '.$always, true, false, []];
        yield 'and with a caller-sensitive child' => ['execution(* *(..)) && withincode(* *(..))', false, true, []];
        yield 'or' => ['get(* Foo::$x) || set(* Foo::$x)', false, false, []];
        yield 'or with a runtime branch' => ['get(* Foo::$x) || '.$always, true, false, []];
        yield 'or binding the same names' => ['(this(a) && execution(* *(..))) || (target(a) && call(* *(..)))', true, true, ['a']];
        yield 'not' => ['!call(* *(..))', false, false, []];
        yield 'not of a runtime pointcut' => ['!'.$always, true, false, []];
        yield 'not of a caller-sensitive pointcut' => ['!withincode(* *(..))', false, true, []];
        yield 'not of cflow' => ['!cflow(execution(* *(..)))', true, false, []];
        yield 'parenthesised' => ['(cflow(execution(* *(..))) || '.$always.') && call(* *(..))', true, false, []];
        yield 'binding through nested cflow' => ['cflow(call(* *(..)) && cflowbelow(this(a)))', true, false, ['a']];
    }

    /**
     * @param list<string> $bindings
     */
    #[Test]
    #[DataProvider('expressions')]
    public function reportsRuntimeCallerSensitivityAndBindings(string $expression, bool $runtime, bool $callerSensitive, array $bindings): void
    {
        $pointcut = Shadow::compile($expression, ['a' => Marker::class]);
        $names = array_keys($pointcut->bindings());
        sort($names);

        self::assertSame($runtime, $pointcut->isRuntime(), 'isRuntime');
        self::assertSame($callerSensitive, $pointcut->isCallerSensitive(), 'isCallerSensitive');
        self::assertSame($bindings, $names, 'bindings');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function staticExpressions(): iterable
    {
        yield 'execution' => ['execution(* *(..))'];
        yield 'call' => ['call(* *(..))'];
        yield 'adviceexecution' => ['adviceexecution()'];
        yield 'handler' => ['handler(Foo)'];
        yield 'get' => ['get(* Foo::$x)'];
        yield 'set' => ['set(* Foo::$x)'];
        yield 'service' => ['service(foo)'];
        yield 'within' => ['within(Foo)'];
        yield 'withincode' => ['withincode(* *(..))'];
    }

    #[Test]
    #[DataProvider('staticExpressions')]
    public function staticDesignatorsLeaveTheRuntimeDecisionOpen(string $expression): void
    {
        $pointcut = Shadow::compile($expression);

        self::assertFalse($pointcut->isRuntime());
        self::assertTrue($pointcut->matchesRuntime(Jp::runtime(new Callee(), 'find', [1])), 'the static half already decided');
    }
}
