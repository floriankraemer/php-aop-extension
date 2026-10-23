<?php

declare(strict_types=1);

namespace AOP\Pointcut\Expression;

use AOP\Exception\PointcutSyntaxException;
use AOP\Exception\UnsupportedPointcutDesignatorException;
use AOP\Pointcut\Expression\Ast\AndNode;
use AOP\Pointcut\Expression\Ast\AnnotationNode;
use AOP\Pointcut\Expression\Ast\ArgsNode;
use AOP\Pointcut\Expression\Ast\AtArgsNode;
use AOP\Pointcut\Expression\Ast\AtTargetNode;
use AOP\Pointcut\Expression\Ast\AtWithinNode;
use AOP\Pointcut\Expression\Ast\BindingCandidateNode;
use AOP\Pointcut\Expression\Ast\ExecutionNode;
use AOP\Pointcut\Expression\Ast\NodeInterface;
use AOP\Pointcut\Expression\Ast\NotNode;
use AOP\Pointcut\Expression\Ast\OrNode;
use AOP\Pointcut\Expression\Ast\ParamListNode;
use AOP\Pointcut\Expression\Ast\ReferenceNode;
use AOP\Pointcut\Expression\Ast\ServiceNode;
use AOP\Pointcut\Expression\Ast\TargetNode;
use AOP\Pointcut\Expression\Ast\ThisNode;
use AOP\Pointcut\Expression\Ast\TypePatternNode;
use AOP\Pointcut\Expression\Ast\WildcardParamNode;
use AOP\Pointcut\Expression\Ast\WithinNode;

/**
 * Recursive-descent parser for the pointcut expression language (see plan §2.2).
 */
final class Parser
{
    private const UNSUPPORTED = [
        'call', 'cflow', 'cflowbelow', 'get', 'set', 'withincode', 'if', 'initialization',
        'preinitialization', 'staticinitialization', 'handler', 'adviceexecution',
    ];

    /** @var list<Token> */
    private array $tokens = [];
    private int $pos = 0;
    private string $expression = '';

    public function parse(string $expression): NodeInterface
    {
        $this->expression = $expression;
        $this->tokens = (new Lexer())->tokenize($expression);
        $this->pos = 0;

        $node = $this->parseOr();
        if (TokenType::Eof !== $this->current()->type) {
            throw $this->unexpected('end of expression');
        }

        return $node;
    }

    private function parseOr(): NodeInterface
    {
        $node = $this->parseAnd();
        while (null !== $this->accept(TokenType::Or)) {
            $node = new OrNode($node, $this->parseAnd());
        }

        return $node;
    }

    private function parseAnd(): NodeInterface
    {
        $node = $this->parseUnary();
        while (null !== $this->accept(TokenType::And)) {
            $node = new AndNode($node, $this->parseUnary());
        }

        return $node;
    }

    private function parseUnary(): NodeInterface
    {
        if (null !== $this->accept(TokenType::Not)) {
            return new NotNode($this->parseUnary());
        }

        return $this->parsePrimary();
    }

    private function parsePrimary(): NodeInterface
    {
        $token = $this->current();

        if (null !== $this->accept(TokenType::LParen)) {
            $node = $this->parseOr();
            $this->expect(TokenType::RParen, '")"');

            return $node;
        }

        if (TokenType::Keyword === $token->type) {
            $this->advance();
            $this->expect(TokenType::LParen, '"("');
            $node = $this->parseDesignator($token->value);
            $this->expect(TokenType::RParen, '")"');

            return $node;
        }

        if (TokenType::Word === $token->type) {
            return $this->parseReference($token);
        }

        throw $this->unexpected('pointcut designator or reference');
    }

    /**
     * Designator name => parser of its argument list (between the parentheses). Every name here must also be in
     * Lexer::DESIGNATORS; add a designator by adding an entry to both.
     *
     * @return array<string, \Closure(): NodeInterface>
     */
    private function designators(): array
    {
        return [
            'execution' => fn (): NodeInterface => $this->parseExecution(),
            'within' => fn (): NodeInterface => new WithinNode($this->typePattern($this->expectWord(), false, $this->acceptPlus())),
            'this' => fn (): NodeInterface => new ThisNode($this->typeOrBinding()),
            'target' => fn (): NodeInterface => new TargetNode($this->typeOrBinding()),
            '@annotation' => fn (): NodeInterface => new AnnotationNode($this->typeOrBinding()),
            '@within' => fn (): NodeInterface => new AtWithinNode($this->typeOrBinding()),
            '@target' => fn (): NodeInterface => new AtTargetNode($this->typeOrBinding()),
            'args' => fn (): NodeInterface => new ArgsNode($this->paramList(true)),
            '@args' => fn (): NodeInterface => new AtArgsNode($this->paramList(true)),
            'service' => fn (): NodeInterface => new ServiceNode($this->expectWord()->value),
        ];
    }

    private function parseDesignator(string $name): NodeInterface
    {
        $parse = $this->designators()[$name] ?? throw UnsupportedPointcutDesignatorException::forDesignator($name);

        return $parse();
    }

    private function parseExecution(): ExecutionNode
    {
        $modifier = null;
        $current = $this->current();
        if (
            TokenType::Word === $current->type
            && ('public' === $current->value || 'protected' === $current->value)
            && in_array($this->peek(1)->type, [TokenType::Word, TokenType::Keyword, TokenType::Question], true)
        ) {
            $modifier = $current->value;
            $this->advance();
        }

        $nullable = null !== $this->accept(TokenType::Question);
        $returnWord = $this->expectWord();
        $returnPlus = $this->acceptPlus();
        $returnPattern = '*' === $returnWord->value && !$nullable && !$returnPlus
            ? null
            : $this->typePattern($returnWord, $nullable, $returnPlus);

        // Two-token lookahead: pattern followed by `::` is the declaring type, followed by `(` the name.
        $word = $this->expectWord();
        $plusPosition = $this->current()->position;
        $plus = $this->acceptPlus();
        $declaringType = null;
        if (null !== $this->accept(TokenType::DoubleColon)) {
            $declaringType = $this->typePattern($word, false, $plus);
            $word = $this->expectWord();
        } elseif ($plus) {
            throw PointcutSyntaxException::at('"+" is not allowed on a method name pattern', $plusPosition, $this->expression);
        }
        $name = $this->memberName($word, null === $declaringType);

        $this->expect(TokenType::LParen, '"("');

        $params = $this->paramList(false);
        $this->expect(TokenType::RParen, '")"');

        return new ExecutionNode($modifier, $returnPattern, $declaringType, $name, $params);
    }

    /**
     * A method name pattern. Without a declaring type the pattern names a function, so it may be namespaced
     * (`App\Util\fmt*`, `str_*`).
     */
    private function memberName(Token $word, bool $allowNamespace): string
    {
        $segment = '[A-Za-z0-9_*]+';
        $valid = $allowNamespace
            ? 1 === preg_match('/^\\\\?'.$segment.'(\\\\'.$segment.')*$/', $word->value)
            : 1 === preg_match('/^'.$segment.'$/', $word->value);
        if (!$valid) {
            throw PointcutSyntaxException::at(sprintf('Invalid method name pattern "%s"', $word->value), $word->position, $this->expression);
        }

        return ltrim($word->value, '\\');
    }

    private function parseReference(Token $word): ReferenceNode
    {
        $next = $this->peek(1);

        if (TokenType::DoubleColon === $next->type) {
            $this->advance();
            $this->advance();
            $method = $this->expectWord();
            $this->expect(TokenType::LParen, '"("');
            $this->expect(TokenType::RParen, '")"');

            return new ReferenceNode($this->className($word), $this->identifier($method));
        }

        if (TokenType::LParen !== $next->type) {
            throw PointcutSyntaxException::at(sprintf('Unexpected "%s", expected pointcut designator or reference', $word->value), $word->position, $this->expression);
        }

        if (in_array($word->value, self::UNSUPPORTED, true) || TokenType::RParen !== $this->peek(2)->type) {
            throw UnsupportedPointcutDesignatorException::forDesignator($word->value);
        }

        $this->advance();
        $this->advance();
        $this->advance();

        return new ReferenceNode(null, $this->identifier($word));
    }

    private function typeOrBinding(): TypePatternNode|BindingCandidateNode
    {
        $word = $this->expectWord();
        $plus = $this->acceptPlus();

        return !$plus && $this->isBindingCandidate($word->value)
            ? new BindingCandidateNode($word->value)
            : $this->typePattern($word, false, $plus);
    }

    /**
     * Parses params up to, but excluding, the closing ")".
     */
    private function paramList(bool $allowBinding): ParamListNode
    {
        $params = [];
        $ellipsis = false;

        if (TokenType::RParen === $this->current()->type) {
            return new ParamListNode([], false);
        }

        while (true) {
            if (null !== $this->accept(TokenType::Ellipsis)) {
                $ellipsis = true;
                break;
            }
            $params[] = $this->param($allowBinding);
            if (null === $this->accept(TokenType::Comma)) {
                break;
            }
        }

        return new ParamListNode($params, $ellipsis);
    }

    private function param(bool $allowBinding): TypePatternNode|WildcardParamNode|BindingCandidateNode
    {
        $nullable = null !== $this->accept(TokenType::Question);
        $word = $this->expectWord();
        $plus = $this->acceptPlus();

        if (!$nullable && !$plus) {
            if ('*' === $word->value) {
                return new WildcardParamNode();
            }
            if ($allowBinding && $this->isBindingCandidate($word->value)) {
                return new BindingCandidateNode($word->value);
            }
        }

        return $this->typePattern($word, $nullable, $plus);
    }

    private function typePattern(Token $word, bool $nullable, bool $subtypes): TypePatternNode
    {
        $pattern = str_starts_with($word->value, '\\') ? substr($word->value, 1) : $word->value;
        foreach (explode('\\', $pattern) as $segment) {
            if (1 !== preg_match('/^[A-Za-z0-9_*]+$/', $segment)) {
                throw PointcutSyntaxException::at(sprintf('Invalid type pattern "%s"', $word->value), $word->position, $this->expression);
            }
            if (str_contains($segment, '**') && '**' !== $segment) {
                throw PointcutSyntaxException::at(sprintf('"**" must be a whole namespace segment in "%s"', $word->value), $word->position, $this->expression);
            }
        }

        return new TypePatternNode($pattern, $nullable, $subtypes);
    }

    private function isBindingCandidate(string $value): bool
    {
        return 1 === preg_match('/^[A-Za-z0-9_]+$/', $value);
    }

    private function className(Token $word): string
    {
        if (1 !== preg_match('/^\\\\?[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $word->value)) {
            throw PointcutSyntaxException::at(sprintf('Invalid class name "%s"', $word->value), $word->position, $this->expression);
        }

        return ltrim($word->value, '\\');
    }

    private function identifier(Token $word): string
    {
        if (1 !== preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $word->value)) {
            throw PointcutSyntaxException::at(sprintf('Invalid pointcut name "%s"', $word->value), $word->position, $this->expression);
        }

        return $word->value;
    }

    private function current(): Token
    {
        return $this->tokens[$this->pos];
    }

    private function peek(int $offset): Token
    {
        return $this->tokens[min($this->pos + $offset, count($this->tokens) - 1)];
    }

    private function advance(): void
    {
        if (TokenType::Eof !== $this->current()->type) {
            ++$this->pos;
        }
    }

    private function accept(TokenType $type): ?Token
    {
        $token = $this->current();
        if ($token->type !== $type) {
            return null;
        }
        $this->advance();

        return $token;
    }

    private function acceptPlus(): bool
    {
        return null !== $this->accept(TokenType::Plus);
    }

    private function expect(TokenType $type, string $description): Token
    {
        return $this->accept($type) ?? throw $this->unexpected($description);
    }

    /**
     * Designator keywords are plain words wherever a pattern is expected.
     */
    private function expectWord(): Token
    {
        $token = $this->current();
        if (TokenType::Word !== $token->type && TokenType::Keyword !== $token->type) {
            throw $this->unexpected('pattern or identifier');
        }
        $this->advance();

        return $token;
    }

    private function unexpected(string $expected): PointcutSyntaxException
    {
        $token = $this->current();
        $found = TokenType::Eof === $token->type ? 'end of expression' : sprintf('"%s"', $token->value);

        return PointcutSyntaxException::at(sprintf('Unexpected %s, expected %s', $found, $expected), $token->position, $this->expression);
    }
}
