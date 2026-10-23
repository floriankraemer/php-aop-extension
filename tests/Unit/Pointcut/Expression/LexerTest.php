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
        yield 'field pattern' => ['get(public ?string App\Dto+::$na*)', [
            [TokenType::Keyword, 'get'], [TokenType::LParen, '('], [TokenType::Word, 'public'], [TokenType::Question, '?'],
            [TokenType::Word, 'string'], [TokenType::Word, 'App\Dto'], [TokenType::Plus, '+'], [TokenType::DoubleColon, '::'],
            [TokenType::Variable, '$na*'], [TokenType::RParen, ')'], [TokenType::Eof, ''],
        ]];
        yield 'new keywords' => ['call(x) cflow(x) cflowbelow(x) if(x) handler(x) set(x) @this(x) @withincode(x) withincode(x)', [
            [TokenType::Keyword, 'call'], [TokenType::LParen, '('], [TokenType::Word, 'x'], [TokenType::RParen, ')'],
            [TokenType::Keyword, 'cflow'], [TokenType::LParen, '('], [TokenType::Word, 'x'], [TokenType::RParen, ')'],
            [TokenType::Keyword, 'cflowbelow'], [TokenType::LParen, '('], [TokenType::Word, 'x'], [TokenType::RParen, ')'],
            [TokenType::Keyword, 'if'], [TokenType::LParen, '('], [TokenType::Word, 'x'], [TokenType::RParen, ')'],
            [TokenType::Keyword, 'handler'], [TokenType::LParen, '('], [TokenType::Word, 'x'], [TokenType::RParen, ')'],
            [TokenType::Keyword, 'set'], [TokenType::LParen, '('], [TokenType::Word, 'x'], [TokenType::RParen, ')'],
            [TokenType::Keyword, '@this'], [TokenType::LParen, '('], [TokenType::Word, 'x'], [TokenType::RParen, ')'],
            [TokenType::Keyword, '@withincode'], [TokenType::LParen, '('], [TokenType::Word, 'x'], [TokenType::RParen, ')'],
            [TokenType::Keyword, 'withincode'], [TokenType::LParen, '('], [TokenType::Word, 'x'], [TokenType::RParen, ')'],
            [TokenType::Eof, ''],
        ]];
        yield 'new designator names are words without parenthesis' => ['get set if', [
            [TokenType::Word, 'get'], [TokenType::Word, 'set'], [TokenType::Word, 'if'], [TokenType::Eof, ''],
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
        yield 'lone dollar' => ['args($)', 5];
        yield 'hash' => ['args(#x)', 5];
    }
}
