<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Advice;

use AOP\Advice\AdviceType;
use AOP\Attribute\After;
use AOP\Attribute\AfterReturning;
use AOP\Attribute\AfterThrowing;
use AOP\Attribute\Around;
use AOP\Attribute\Before;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AdviceTypeTest extends TestCase
{
    #[Test]
    public function casesAreDeclaredInPrecedenceOrder(): void
    {
        self::assertSame(
            [
                AdviceType::AROUND,
                AdviceType::BEFORE,
                AdviceType::AFTER,
                AdviceType::AFTER_RETURNING,
                AdviceType::AFTER_THROWING,
            ],
            AdviceType::cases(),
        );
    }

    /**
     * @return iterable<string, array{AdviceType, int}>
     */
    public static function precedenceProvider(): iterable
    {
        yield 'around' => [AdviceType::AROUND, 0];
        yield 'before' => [AdviceType::BEFORE, 1];
        yield 'after' => [AdviceType::AFTER, 2];
        yield 'after returning' => [AdviceType::AFTER_RETURNING, 3];
        yield 'after throwing' => [AdviceType::AFTER_THROWING, 4];
    }

    #[Test]
    #[DataProvider('precedenceProvider')]
    public function precedenceIsFixed(AdviceType $type, int $expected): void
    {
        self::assertSame($expected, $type->precedence());
    }

    /**
     * @return iterable<string, array{AdviceType, string}>
     */
    public static function backingValueProvider(): iterable
    {
        yield 'around' => [AdviceType::AROUND, 'around'];
        yield 'before' => [AdviceType::BEFORE, 'before'];
        yield 'after' => [AdviceType::AFTER, 'after'];
        yield 'after returning' => [AdviceType::AFTER_RETURNING, 'after_returning'];
        yield 'after throwing' => [AdviceType::AFTER_THROWING, 'after_throwing'];
    }

    #[Test]
    #[DataProvider('backingValueProvider')]
    public function backingValueIsSnakeCaseName(AdviceType $type, string $value): void
    {
        self::assertSame($value, $type->value);
    }

    /**
     * @return iterable<string, array{AdviceType, class-string}>
     */
    public static function attributeClassProvider(): iterable
    {
        yield 'around' => [AdviceType::AROUND, Around::class];
        yield 'before' => [AdviceType::BEFORE, Before::class];
        yield 'after' => [AdviceType::AFTER, After::class];
        yield 'after returning' => [AdviceType::AFTER_RETURNING, AfterReturning::class];
        yield 'after throwing' => [AdviceType::AFTER_THROWING, AfterThrowing::class];
    }

    #[Test]
    #[DataProvider('attributeClassProvider')]
    public function attributeClassMatchesType(AdviceType $type, string $attributeClass): void
    {
        self::assertSame($attributeClass, $type->attributeClass());
    }
}
