<?php

declare(strict_types=1);

namespace AOP\Pointcut\Expression;

use AOP\Exception\PointcutSyntaxException;
use AOP\Exception\UnsupportedPointcutDesignatorException;
use AOP\Pointcut\Expression\Ast\AdviceExecutionNode;
use AOP\Pointcut\Expression\Ast\AndNode;
use AOP\Pointcut\Expression\Ast\AnnotationNode;
use AOP\Pointcut\Expression\Ast\ArgsNode;
use AOP\Pointcut\Expression\Ast\AtArgsNode;
use AOP\Pointcut\Expression\Ast\AtTargetNode;
use AOP\Pointcut\Expression\Ast\AtThisNode;
use AOP\Pointcut\Expression\Ast\AtWithincodeNode;
use AOP\Pointcut\Expression\Ast\AtWithinNode;
use AOP\Pointcut\Expression\Ast\BindingCandidateNode;
use AOP\Pointcut\Expression\Ast\CallNode;
use AOP\Pointcut\Expression\Ast\CflowNode;
use AOP\Pointcut\Expression\Ast\ExecutionNode;
use AOP\Pointcut\Expression\Ast\FieldPatternNode;
use AOP\Pointcut\Expression\Ast\GetNode;
use AOP\Pointcut\Expression\Ast\HandlerNode;
use AOP\Pointcut\Expression\Ast\IfNode;
use AOP\Pointcut\Expression\Ast\InitializationNode;
use AOP\Pointcut\Expression\Ast\NodeInterface;
use AOP\Pointcut\Expression\Ast\NotNode;
use AOP\Pointcut\Expression\Ast\OrNode;
use AOP\Pointcut\Expression\Ast\ParamListNode;
use AOP\Pointcut\Expression\Ast\ReferenceNode;
use AOP\Pointcut\Expression\Ast\ServiceNode;
use AOP\Pointcut\Expression\Ast\SetNode;
use AOP\Pointcut\Expression\Ast\TargetNode;
use AOP\Pointcut\Expression\Ast\ThisNode;
use AOP\Pointcut\Expression\Ast\TypePatternNode;
use AOP\Pointcut\Expression\Ast\WildcardParamNode;
use AOP\Pointcut\Expression\Ast\WithincodeNode;
use AOP\Pointcut\Expression\Ast\WithinNode;
use AOP\Pointcut\Pattern\ModifierPattern;

/**
 * Recursive-descent parser for the pointcut expression language (grammar: docs/internals/contract.md, "Pointcut notes (stream E)").
 */
final class Parser
{
    private const FIELD_MODIFIERS = ['public', 'protected', 'private', 'static', 'readonly'];

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
            'call' => fn (): NodeInterface => new CallNode($this->parseExecution()),
            'withincode' => fn (): NodeInterface => new WithincodeNode($this->parseExecution()),
            '@withincode' => fn (): NodeInterface => new AtWithincodeNode($this->typeOrBinding()),
            '@this' => fn (): NodeInterface => new AtThisNode($this->typeOrBinding()),
            'cflow' => fn (): NodeInterface => new CflowNode($this->parseOr(), false),
            'cflowbelow' => fn (): NodeInterface => new CflowNode($this->parseOr(), true),
            'if' => fn (): NodeInterface => $this->parseIf(),
            'initialization' => fn (): NodeInterface => $this->parseInitialization(),
            'adviceexecution' => fn (): NodeInterface => new AdviceExecutionNode(),
            'handler' => fn (): NodeInterface => new HandlerNode($this->typePattern($this->expectWord(), false, $this->acceptPlus())),
            'get' => fn (): NodeInterface => new GetNode($this->parseField()),
            'set' => fn (): NodeInterface => new SetNode($this->parseField()),
            'preinitialization' => fn (): never => throw new UnsupportedPointcutDesignatorException('Unsupported pointcut designator "preinitialization": PHP has no pre-initialization join point. Constructor arguments are evaluated by the caller before the constructor is entered, and there is no separate phase before a parent constructor runs. Use execution(Type::__construct(..)) with before or around advice, or initialization(Type+).'),
            'staticinitialization' => fn (): never => throw new UnsupportedPointcutDesignatorException('Unsupported pointcut designator "staticinitialization": PHP has no static initialization join point. Classes are linked without running any class initializer (there are no static initializer blocks), so there is nothing to intercept when a class is loaded.'),
        ];
    }

    private function parseDesignator(string $name): NodeInterface
    {
        $parse = $this->designators()[$name] ?? throw UnsupportedPointcutDesignatorException::forDesignator($name);

        return $parse();
    }

    private function parseExecution(): ExecutionNode
    {
        $modifiers = $this->modifiers(ModifierPattern::MODIFIERS, 'method');

        // A constructor pattern (`Type::__construct(..)`) may omit the return type, which constructors do not have.
        $returnPattern = null;
        if (!$this->isConstructorPatternStart()) {
            $nullable = null !== $this->accept(TokenType::Question);
            $returnWord = $this->expectWord();
            $returnPlus = $this->acceptPlus();
            $returnPattern = '*' === $returnWord->value && !$nullable && !$returnPlus
                ? null
                : $this->typePattern($returnWord, $nullable, $returnPlus);
        }

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

        return new ExecutionNode($modifiers, $returnPattern, $declaringType, $name, $params);
    }

    /**
     * The leading modifiers of a method, constructor or field pattern: known words, each optionally negated with `!`.
     * A word that is clearly meant as a modifier but is not one is reported as an unknown modifier.
     *
     * @param list<string>                         $known
     * @param 'method'|'initialization'|'field' $context
     *
     * @return list<string>
     */
    private function modifiers(array $known, string $context): array
    {
        $modifiers = [];
        while (true) {
            $negated = TokenType::Not === $this->current()->type;
            $word = $this->peek($negated ? 1 : 0);
            if ($negated && TokenType::Word !== $word->type) {
                throw $this->unexpected('modifier after "!"');
            }
            if ($negated && !in_array($word->value, $known, true)) {
                throw $this->unknownModifier($word, $known);
            }
            if (TokenType::Word !== $word->type || !in_array($word->value, $known, true)) {
                break;
            }
            $following = $this->peek($negated ? 2 : 1)->type;
            if (!in_array($following, [TokenType::Word, TokenType::Keyword, TokenType::Question, TokenType::Not], true)) {
                break; // a modifier word is followed by the rest of the pattern, otherwise it is a name
            }
            $modifiers[] = ($negated ? '!' : '').$word->value;
            $this->advance();
            if ($negated) {
                $this->advance();
            }
        }

        if ($this->startsWithUnknownModifier($context)) {
            throw $this->unknownModifier($this->current(), $known);
        }

        return $modifiers;
    }

    /**
     * Does a word that is no modifier stand where one could be? `abstrct * Foo::bar()`: three words in a row, a word
     * before `?type`, or a word before a constructor pattern can only be a modifier.
     *
     * @param 'method'|'initialization'|'field' $context
     */
    private function startsWithUnknownModifier(string $context): bool
    {
        $current = $this->current();
        if (TokenType::Word !== $current->type) {
            return false;
        }
        $isWord = static fn (Token $t): bool => TokenType::Word === $t->type || TokenType::Keyword === $t->type;
        $next = $this->peek(1);

        return match ($context) {
            'initialization' => '*' !== $current->value && $isWord($next),
            default => ($isWord($next) && $isWord($this->peek(2)))
                || TokenType::Question === $next->type
                || ('method' === $context && '*' !== $current->value && $isWord($next)
                    && TokenType::DoubleColon === $this->peek(2)->type && '__construct' === $this->peek(3)->value),
        };
    }

    /**
     * @param list<string> $known
     */
    private function unknownModifier(Token $token, array $known): PointcutSyntaxException
    {
        return PointcutSyntaxException::at(sprintf('Unknown modifier "%s": expected %s, each optionally negated with "!"', $token->value, implode(', ', $known)), $token->position, $this->expression);
    }

    /**
     * `Type[+]::__construct(` follows: a constructor pattern without return type.
     */
    private function isConstructorPatternStart(): bool
    {
        if (TokenType::Word !== $this->current()->type) {
            return false;
        }
        $next = TokenType::Plus === $this->peek(1)->type ? 2 : 1;

        return TokenType::DoubleColon === $this->peek($next)->type
            && '__construct' === $this->peek($next + 1)->value
            && TokenType::LParen === $this->peek($next + 2)->type;
    }

    /**
     * `if(Class::method())` or `if(method())`.
     */
    private function parseIf(): IfNode
    {
        $first = $this->expectWord();
        $className = null;
        if (null !== $this->accept(TokenType::DoubleColon)) {
            $className = $this->className($first);
            $first = $this->expectWord();
        }
        $method = $this->identifier($first);
        $this->expect(TokenType::LParen, '"("');
        $this->expect(TokenType::RParen, '")"');

        return new IfNode($className, $method);
    }

    /**
     * `Type::__construct(params)` or the shorthand `Type` / `Type+` (any constructor).
     */
    private function parseInitialization(): InitializationNode
    {
        $modifiers = $this->modifiers(ModifierPattern::MODIFIERS, 'initialization');

        $word = $this->expectWord();
        $plus = $this->acceptPlus();
        $declaringType = $this->typePattern($word, false, $plus);
        if (null === $this->accept(TokenType::DoubleColon)) {
            if ([] !== $modifiers) {
                throw PointcutSyntaxException::at(sprintf('A modifier needs a full constructor pattern, expected "%s::__construct(..)"', $word->value), $word->position, $this->expression);
            }

            return new InitializationNode([], $declaringType, new ParamListNode([], true));
        }
        $name = $this->expectWord();
        if ('__construct' !== $name->value) {
            throw PointcutSyntaxException::at(sprintf('initialization() takes a constructor pattern, expected "__construct" but found "%s"', $name->value), $name->position, $this->expression);
        }
        $this->expect(TokenType::LParen, '"("');
        $params = $this->paramList(false);
        $this->expect(TokenType::RParen, '")"');

        return new InitializationNode($modifiers, $declaringType, $params);
    }

    /**
     * `[modifiers] [type] Declaring\Type[+]::$name`.
     */
    private function parseField(): FieldPatternNode
    {
        $modifiers = $this->modifiers(self::FIELD_MODIFIERS, 'field');

        $nullable = null !== $this->accept(TokenType::Question);
        $first = $this->expectWord();
        $plus = $this->acceptPlus();
        if (!$nullable && TokenType::DoubleColon === $this->current()->type) {
            $type = null;
            $declaringType = $this->typePattern($first, false, $plus);
        } else {
            $type = '*' === $first->value && !$nullable && !$plus ? null : $this->typePattern($first, $nullable, $plus);
            $declaring = $this->expectWord();
            $declaringType = $this->typePattern($declaring, false, $this->acceptPlus());
        }
        $this->expect(TokenType::DoubleColon, '"::"');

        $variable = $this->expect(TokenType::Variable, 'property name such as "$name"');
        $name = substr($variable->value, 1);

        return new FieldPatternNode($modifiers, $type, $declaringType, $name);
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

        if (TokenType::RParen !== $this->peek(2)->type) {
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
