<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\JoinPoint;

use AOP\JoinPoint\JoinPointKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class JoinPointKindTest extends TestCase
{
    /**
     * @return iterable<string, array{JoinPointKind, string, bool, bool}>
     */
    public static function kinds(): iterable
    {
        yield 'method-execution' => [JoinPointKind::METHOD_EXECUTION, 'method-execution', false, true];
        yield 'method-call' => [JoinPointKind::METHOD_CALL, 'method-call', true, false];
        yield 'constructor-execution' => [JoinPointKind::CONSTRUCTOR_EXECUTION, 'constructor-execution', false, true];
        yield 'constructor-call' => [JoinPointKind::CONSTRUCTOR_CALL, 'constructor-call', true, false];
        yield 'function-execution' => [JoinPointKind::FUNCTION_EXECUTION, 'function-execution', false, true];
        yield 'function-call' => [JoinPointKind::FUNCTION_CALL, 'function-call', true, false];
        yield 'initialization' => [JoinPointKind::INITIALIZATION, 'initialization', false, false];
        yield 'field-get' => [JoinPointKind::FIELD_GET, 'field-get', false, false];
        yield 'field-set' => [JoinPointKind::FIELD_SET, 'field-set', false, false];
        yield 'exception-handler' => [JoinPointKind::EXCEPTION_HANDLER, 'exception-handler', false, false];
        yield 'advice-execution' => [JoinPointKind::ADVICE_EXECUTION, 'advice-execution', false, true];
    }

    #[Test]
    #[DataProvider('kinds')]
    public function valueAndCategory(JoinPointKind $kind, string $value, bool $isCall, bool $isExecution): void
    {
        self::assertSame($value, $kind->value);
        self::assertSame($kind, JoinPointKind::from($value));
        self::assertSame($isCall, $kind->isCall());
        self::assertSame($isExecution, $kind->isExecution());
    }

    #[Test]
    public function aKindIsNeverBothCallAndExecution(): void
    {
        foreach (JoinPointKind::cases() as $kind) {
            self::assertFalse($kind->isCall() && $kind->isExecution(), $kind->value);
        }
        self::assertCount(11, JoinPointKind::cases());
    }
}
