<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Pointcut;

use AOP\JoinPoint\JoinPointKind;
use AOP\Pointcut\StaticMatchContext;
use AOP\Tests\Fixtures\Pointcut\Callee;
use AOP\Tests\Fixtures\Pointcut\Caller;
use AOP\Tests\Fixtures\Pointcut\Conditions;
use AOP\Tests\Fixtures\Pointcut\DomainError;
use AOP\Tests\Fixtures\Pointcut\NoConstructor;
use AOP\Tests\Fixtures\Pointcut\Shadow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Which join point kinds each designator applies to: all eleven kinds are built with a plausible shadow and each
 * "match everything" designator must select exactly its own kinds.
 */
final class KindMatrixTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__.'/../../Fixtures/Functions/functions.php';
    }

    /**
     * @return array<string, StaticMatchContext>
     */
    private static function shadows(): array
    {
        $caller = Shadow::caller(Caller::class, 'run');

        return [
            JoinPointKind::METHOD_EXECUTION->value => Shadow::method(Callee::class, 'find', caller: $caller),
            JoinPointKind::METHOD_CALL->value => Shadow::method(Callee::class, 'find', JoinPointKind::METHOD_CALL, $caller),
            JoinPointKind::CONSTRUCTOR_EXECUTION->value => Shadow::constructor(Callee::class, caller: $caller),
            JoinPointKind::CONSTRUCTOR_CALL->value => Shadow::constructor(Callee::class, JoinPointKind::CONSTRUCTOR_CALL, $caller),
            JoinPointKind::FUNCTION_EXECUTION->value => Shadow::function('AOP\Tests\Fixtures\Functions\fmt', caller: $caller),
            JoinPointKind::FUNCTION_CALL->value => Shadow::function('AOP\Tests\Fixtures\Functions\fmt', JoinPointKind::FUNCTION_CALL, $caller),
            JoinPointKind::INITIALIZATION->value => Shadow::initialization(NoConstructor::class),
            JoinPointKind::FIELD_GET->value => Shadow::field(Callee::class, 'count', JoinPointKind::FIELD_GET, $caller),
            JoinPointKind::FIELD_SET->value => Shadow::field(Callee::class, 'count', JoinPointKind::FIELD_SET, $caller),
            JoinPointKind::EXCEPTION_HANDLER->value => Shadow::handler(DomainError::class, $caller),
            JoinPointKind::ADVICE_EXECUTION->value => Shadow::advice(Conditions::class, 'always'),
        ];
    }

    /**
     * @return iterable<string, array{string, list<JoinPointKind>}>
     */
    public static function designators(): iterable
    {
        yield 'execution' => ['execution(* *(..))', [JoinPointKind::METHOD_EXECUTION, JoinPointKind::CONSTRUCTOR_EXECUTION, JoinPointKind::FUNCTION_EXECUTION]];
        yield 'call' => ['call(* *(..))', [JoinPointKind::METHOD_CALL, JoinPointKind::CONSTRUCTOR_CALL, JoinPointKind::FUNCTION_CALL]];
        yield 'initialization' => ['initialization(**+)', [JoinPointKind::INITIALIZATION, JoinPointKind::CONSTRUCTOR_EXECUTION]];
        yield 'adviceexecution' => ['adviceexecution()', [JoinPointKind::ADVICE_EXECUTION]];
        yield 'handler' => ['handler(**)', [JoinPointKind::EXCEPTION_HANDLER]];
        yield 'get' => ['get(* **::$*)', [JoinPointKind::FIELD_GET]];
        yield 'set' => ['set(* **::$*)', [JoinPointKind::FIELD_SET]];
        yield 'withincode' => ['withincode(* *(..))', JoinPointKind::cases()];
    }

    /**
     * @param list<JoinPointKind> $expected
     */
    #[Test]
    #[DataProvider('designators')]
    public function selectsExactlyItsKinds(string $expression, array $expected): void
    {
        $pointcut = Shadow::compile($expression);
        $matched = [];
        foreach (self::shadows() as $kind => $ctx) {
            if (Shadow::matches($pointcut, $ctx)) {
                $matched[] = $kind;
            }
        }
        $wanted = array_map(static fn (JoinPointKind $kind): string => $kind->value, $expected);
        sort($matched);
        sort($wanted);

        self::assertSame($wanted, $matched);
    }

    #[Test]
    public function withincodeWithoutAKnownCallerOnlyAppliesToCodeKinds(): void
    {
        $pointcut = Shadow::compile('withincode(* *(..))');
        $matched = [];
        foreach (self::shadows() as $kind => $ctx) {
            $bare = new StaticMatchContext($ctx->kind, $ctx->targetClass, $ctx->function, $ctx->property, $ctx->caughtType);
            if (Shadow::matches($pointcut, $bare)) {
                $matched[] = $kind;
            }
        }
        sort($matched);

        self::assertSame([
            'advice-execution', 'constructor-execution', 'function-execution', 'initialization', 'method-execution',
        ], $matched);
    }

    #[Test]
    public function aFunctionPatternNeverMatchesAMethodKindAndTheOtherWayAround(): void
    {
        $function = Shadow::compile('call(* AOP\Tests\Fixtures\Functions\fmt(..))');
        $method = Shadow::compile('call(* **::find(..))');

        self::assertTrue(Shadow::matches($function, Shadow::function('AOP\Tests\Fixtures\Functions\fmt', JoinPointKind::FUNCTION_CALL)));
        self::assertFalse(Shadow::matches($function, Shadow::method(Callee::class, 'find', JoinPointKind::METHOD_CALL)));
        self::assertTrue(Shadow::matches($method, Shadow::method(Callee::class, 'find', JoinPointKind::METHOD_CALL)));
        self::assertFalse(Shadow::matches($method, Shadow::function('AOP\Tests\Fixtures\Functions\fmt', JoinPointKind::FUNCTION_CALL)));
    }

    #[Test]
    public function constructorsAreMatchedByTheirNameLikeInExecution(): void
    {
        self::assertTrue(Shadow::matches(Shadow::compile('call(* **::__construct(..))'), Shadow::constructor(Callee::class, JoinPointKind::CONSTRUCTOR_CALL)));
        self::assertTrue(Shadow::matches(Shadow::compile('call(* AOP\Tests\Fixtures\Pointcut\Callee::*(..))'), Shadow::constructor(Callee::class, JoinPointKind::CONSTRUCTOR_CALL)));
        self::assertFalse(Shadow::matches(Shadow::compile('call(* **::__construct(string))'), Shadow::constructor(Callee::class, JoinPointKind::CONSTRUCTOR_CALL)));
    }

    #[Test]
    public function existingDesignatorsHandleTheNewKinds(): void
    {
        $shadows = self::shadows();
        $matches = static fn (string $expression, string $kind): bool => Shadow::matches(Shadow::compile($expression), $shadows[$kind]);

        self::assertTrue($matches('within(**)', 'initialization'), 'the initialized class is the enclosing code');
        self::assertTrue($matches('within(**)', 'advice-execution'), 'the aspect is the enclosing code');
        self::assertTrue($matches('within(**\\Conditions)', 'advice-execution'));
        self::assertFalse($matches('within(**\\Callee)', 'advice-execution'));
        self::assertTrue($matches('this(**)', 'initialization'));
        self::assertFalse($matches('this(**)', 'advice-execution'), 'a static advice method has no this');
        self::assertTrue($matches('target(**)', 'initialization'));
        self::assertFalse($matches('target(**)', 'exception-handler'), 'a catch clause has no target');
        self::assertFalse($matches('@annotation(**)', 'exception-handler'));
        self::assertFalse($matches('@annotation(**)', 'initialization'), 'no constructor, no attributes');
        self::assertTrue($matches('args(..)', 'advice-execution'));
        self::assertTrue($matches('args(*)', 'exception-handler'));
        self::assertFalse($matches('args(*)', 'field-get'));
        self::assertTrue($matches('args(*)', 'field-set'));
    }
}
