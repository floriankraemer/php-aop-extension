<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Pointcut\Expression;

use AOP\Exception\PointcutSyntaxException;
use AOP\Pointcut\Expression\Lexer;
use AOP\Pointcut\Expression\TokenType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LexerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<array{TokenType, string}>}>
     */
    public static function expressions(): iterable
    {
        yield 'execution' => ['execution(* App\Service\*::get*(..))', [
            [TokenType::Keyword, 'execution'], [TokenType::LParen, '('], [TokenType::Word, '*'],
            [TokenType::Word, 'App\Service\*'], [TokenType::DoubleColon, '::'], [TokenType::Word, 'get*'],
            [TokenType::LParen, '('], [TokenType::Ellipsis, '..'], [TokenType::RParen, ')'], [TokenType::RParen, ')'],
            [TokenType::Eof, ''],
        ]];
        yield 'operators' => ['!a && b || c', [
            [TokenType::Not, '!'], [TokenType::Word, 'a'], [TokenType::And, '&&'], [TokenType::Word, 'b'],
            [TokenType::Or, '||'], [TokenType::Word, 'c'], [TokenType::Eof, ''],
        ]];
        yield 'nullable and subtype' => ['?string User+, x', [
            [TokenType::Question, '?'], [TokenType::Word, 'string'], [TokenType::Word, 'User'],
            [TokenType::Plus, '+'], [TokenType::Comma, ','], [TokenType::Word, 'x'], [TokenType::Eof, ''],
        ]];
        yield 'at keyword' => ['@annotation (audit)', [
            [TokenType::Keyword, '@annotation'], [TokenType::LParen, '('], [TokenType::Word, 'audit'],
            [TokenType::RParen, ')'], [TokenType::Eof, ''],
        ]];
        yield 'keyword not followed by paren is a word' => ['args(target)', [
            [TokenType::Keyword, 'args'], [TokenType::LParen, '('], [TokenType::Word, 'target'],
            [TokenType::RParen, ')'], [TokenType::Eof, ''],
        ]];
        yield 'service raw token' => ['service(app.my-service_*)', [
            [TokenType::Keyword, 'service'], [TokenType::LParen, '('], [TokenType::Word, 'app.my-service_*'],
            [TokenType::RParen, ')'], [TokenType::Eof, ''],
        ]];
        yield 'service FQCN service id' => ['service(App\Service\*) || x', [
            [TokenType::Keyword, 'service'], [TokenType::LParen, '('], [TokenType::Word, 'App\Service\*'],
            [TokenType::RParen, ')'], [TokenType::Or, '||'], [TokenType::Word, 'x'], [TokenType::Eof, ''],
        ]];
        yield 'reference' => ['App\Aspect\Common::layer()', [
            [TokenType::Word, 'App\Aspect\Common'], [TokenType::DoubleColon, '::'], [TokenType::Word, 'layer'],
            [TokenType::LParen, '('], [TokenType::RParen, ')'], [TokenType::Eof, ''],
        ]];
        yield 'empty' => ['  ', [[TokenType::Eof, '']]];
    }

    /**
     * @param list<array{TokenType, string}> $expected
     */
    #[Test]
    #[DataProvider('expressions')]
    public function tokenizes(string $expression, array $expected): void
    {
        $actual = array_map(
            static fn ($t): array => [$t->type, $t->value],
            (new Lexer())->tokenize($expression),
        );

        self::assertSame($expected, $actual);
    }

    #[Test]
    public function tracksZeroBasedPositions(): void
    {
        $tokens = (new Lexer())->tokenize('a  && b');

        self::assertSame([0, 3, 6, 7], array_map(static fn ($t): int => $t->position, $tokens));
    }

    #[Test]
    #[DataProvider('invalid')]
    public function rejectsInvalidCharacters(string $expression, int $position): void
    {
        try {
            (new Lexer())->tokenize($expression);
            self::fail('Expected exception.');
        } catch (PointcutSyntaxException $e) {
            self::assertStringContainsString('position '.$position, $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function invalid(): iterable
    {
        yield 'single ampersand' => ['a & b', 2];
        yield 'single pipe' => ['a | b', 2];
        yield 'single colon' => ['a:b', 1];
        yield 'single dot' => ['a.b', 1];
        yield 'dollar' => ['args($x)', 5];
    }
}
