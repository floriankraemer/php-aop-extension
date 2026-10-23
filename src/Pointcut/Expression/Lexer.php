<?php

declare(strict_types=1);

namespace AOP\Pointcut\Expression;

use AOP\Exception\PointcutSyntaxException;

final class Lexer
{
    public const DESIGNATORS = [
        'execution', 'within', 'this', 'target', 'args', 'service',
        '@annotation', '@within', '@target', '@args',
        'call', 'withincode', '@withincode', '@this', 'cflow', 'cflowbelow', 'if', 'initialization',
        'adviceexecution', 'handler', 'get', 'set',
        'preinitialization', 'staticinitialization',
    ];

    /**
     * @return list<Token>
     */
    public function tokenize(string $expression): array
    {
        $tokens = [];
        $length = strlen($expression);
        $i = 0;

        while ($i < $length) {
            $c = $expression[$i];
            $single = match ($c) {
                '(' => TokenType::LParen,
                ')' => TokenType::RParen,
                ',' => TokenType::Comma,
                '!' => TokenType::Not,
                '+' => TokenType::Plus,
                '?' => TokenType::Question,
                default => null,
            };

            if (' ' === $c || "\t" === $c || "\n" === $c || "\r" === $c) {
                ++$i;
            } elseif (null !== $single) {
                $tokens[] = new Token($single, $c, $i);
                ++$i;
                if (TokenType::LParen === $single && $this->isServiceKeyword($tokens)) {
                    $i = $this->readServicePattern($expression, $i, $tokens);
                }
            } elseif (($pair = substr($expression, $i, 2)) === '&&' || '||' === $pair || '::' === $pair || '..' === $pair) {
                $type = match ($pair) {
                    '&&' => TokenType::And,
                    '||' => TokenType::Or,
                    '::' => TokenType::DoubleColon,
                    default => TokenType::Ellipsis,
                };
                $tokens[] = new Token($type, $pair, $i);
                $i += 2;
            } elseif (1 === preg_match('/\G\$[A-Za-z0-9_*]+/', $expression, $m, 0, $i)) {
                $tokens[] = new Token(TokenType::Variable, $m[0], $i);
                $i += strlen($m[0]);
            } elseif (1 === preg_match('/\G@?[A-Za-z0-9_*\\\\]+/', $expression, $m, 0, $i)) {
                $word = $m[0];
                $isKeyword = in_array($word, self::DESIGNATORS, true) && '(' === $this->nextNonSpace($expression, $i + strlen($word));
                $tokens[] = new Token($isKeyword ? TokenType::Keyword : TokenType::Word, $word, $i);
                $i += strlen($word);
            } else {
                throw PointcutSyntaxException::at(sprintf('Unexpected character "%s"', $c), $i, $expression);
            }
        }

        $tokens[] = new Token(TokenType::Eof, '', $length);

        return $tokens;
    }

    /**
     * @param list<Token> $tokens
     */
    private function isServiceKeyword(array $tokens): bool
    {
        $previous = $tokens[count($tokens) - 2] ?? null;

        return null !== $previous && TokenType::Keyword === $previous->type && 'service' === $previous->value;
    }

    /**
     * Service ids contain dots and dashes (or are FQCNs when autowired), so service() takes one raw token.
     *
     * @param list<Token> $tokens
     */
    private function readServicePattern(string $expression, int $i, array &$tokens): int
    {
        if (1 !== preg_match('/\G\s*([A-Za-z0-9_.*\\\\\-]+)/', $expression, $m, PREG_OFFSET_CAPTURE, $i)) {
            return $i;
        }
        $tokens[] = new Token(TokenType::Word, $m[1][0], $m[1][1]);

        return $m[1][1] + strlen($m[1][0]);
    }

    private function nextNonSpace(string $expression, int $from): ?string
    {
        $rest = ltrim(substr($expression, $from));

        return '' === $rest ? null : $rest[0];
    }
}
