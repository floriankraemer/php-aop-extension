<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Pointcut\Expression;

use AOP\Exception\PointcutSyntaxException;
use AOP\Exception\UnsupportedPointcutDesignatorException;
use AOP\Pointcut\Expression\Ast\AdviceExecutionNode;
use AOP\Pointcut\Expression\Ast\AndNode;
use AOP\Pointcut\Expression\Ast\ArgsNode;
use AOP\Pointcut\Expression\Ast\AtThisNode;
use AOP\Pointcut\Expression\Ast\AtWithincodeNode;
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
use AOP\Pointcut\Expression\Ast\SetNode;
use AOP\Pointcut\Expression\Ast\ThisNode;
use AOP\Pointcut\Expression\Ast\TypePatternNode;
use AOP\Pointcut\Expression\Ast\WildcardParamNode;
use AOP\Pointcut\Expression\Ast\WithincodeNode;
use AOP\Pointcut\Expression\Ast\WithinNode;
use AOP\Pointcut\Expression\Lexer;
use AOP\Pointcut\Expression\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NewDesignatorsParserTest extends TestCase
{
    private static function t(string $pattern, bool $nullable = false, bool $subtypes = false): TypePatternNode
    {
        return new TypePatternNode($pattern, $nullable, $subtypes);
    }

    private static function anyParams(): ParamListNode
    {
        return new ParamListNode([], true);
    }

    /**
     * @return iterable<string, array{string, NodeInterface}>
     */
    public static function valid(): iterable
    {
        $find = new ExecutionNode([], null, self::t('App\Foo'), 'find', new ParamListNode([self::t('int')]));

        yield 'call' => ['call(* App\Foo::find(int))', new CallNode(new ExecutionNode([], null, self::t('App\Foo'), 'find', new ParamListNode([self::t('int')])))];
        yield 'call with modifier, return and wildcards' => [
            'call(public ?string App\**+::get*(..))',
            new CallNode(new ExecutionNode(['public'], self::t('string', true), self::t('App\**', false, true), 'get*', self::anyParams())),
        ];
        yield 'call function' => ['call(* App\Util\fmt*(..))', new CallNode(new ExecutionNode([], null, null, 'App\Util\fmt*', self::anyParams()))];
        yield 'call constructor without return type' => [
            'call(App\Foo::__construct(int, ..))',
            new CallNode(new ExecutionNode([], null, self::t('App\Foo'), '__construct', new ParamListNode([self::t('int')], true))),
        ];
        yield 'call constructor with plus' => [
            'call(App\Foo+::__construct())',
            new CallNode(new ExecutionNode([], null, self::t('App\Foo', false, true), '__construct', new ParamListNode([]))),
        ];
        yield 'execution constructor without return type' => [
            'execution(public App\Foo::__construct(..))',
            new ExecutionNode(['public'], null, self::t('App\Foo'), '__construct', self::anyParams()),
        ];
        yield 'withincode' => ['withincode(* App\Foo::find(int))', new WithincodeNode($find)];
        yield 'withincode constructor' => [
            'withincode(App\Foo::__construct(..))',
            new WithincodeNode(new ExecutionNode([], null, self::t('App\Foo'), '__construct', self::anyParams())),
        ];
        yield '@withincode type' => ['@withincode(App\Attr)', new AtWithincodeNode(self::t('App\Attr'))];
        yield '@withincode binding' => ['@withincode(tag)', new AtWithincodeNode(new BindingCandidateNode('tag'))];
        yield '@this type' => ['@this(App\Attr)', new AtThisNode(self::t('App\Attr'))];
        yield '@this binding' => ['@this(attr)', new AtThisNode(new BindingCandidateNode('attr'))];
        yield 'cflow' => ['cflow(within(App\Foo))', new CflowNode(new WithinNode(self::t('App\Foo')), false)];
        yield 'cflowbelow' => ['cflowbelow(within(App\Foo))', new CflowNode(new WithinNode(self::t('App\Foo')), true)];
        yield 'cflow of a combination' => [
            'cflow(within(A) && !within(B))',
            new CflowNode(new AndNode(new WithinNode(self::t('A')), new NotNode(new WithinNode(self::t('B')))), false),
        ];
        yield 'cflow with binding' => ['cflow(this(a) && args(b, ..))', new CflowNode(new AndNode(new ThisNode(new BindingCandidateNode('a')), new ArgsNode(new ParamListNode([new BindingCandidateNode('b')], true))), false)];
        yield 'cflow nested' => ['cflow(cflowbelow(within(A)))', new CflowNode(new CflowNode(new WithinNode(self::t('A')), true), false)];
        yield 'cflow in a combination' => [
            'within(A) && cflow(within(B)) || !cflow(within(C))',
            new OrNode(
                new AndNode(new WithinNode(self::t('A')), new CflowNode(new WithinNode(self::t('B')), false)),
                new NotNode(new CflowNode(new WithinNode(self::t('C')), false)),
            ),
        ];
        yield 'cflow of parenthesised or' => ['cflow((within(A) || within(B)))', new CflowNode(new OrNode(new WithinNode(self::t('A')), new WithinNode(self::t('B'))), false)];
        yield 'if qualified' => ['if(App\Conditions::isActive())', new IfNode('App\Conditions', 'isActive')];
        yield 'if leading backslash' => ['if(\App\Conditions::isActive())', new IfNode('App\Conditions', 'isActive')];
        yield 'if short' => ['if(isActive())', new IfNode(null, 'isActive')];
        yield 'if with designator-like names' => ['if(within())', new IfNode(null, 'within')];
        yield 'if combined' => ['within(A) && if(check())', new AndNode(new WithinNode(self::t('A')), new IfNode(null, 'check'))];
        yield 'initialization' => [
            'initialization(App\Foo::__construct(..))',
            new InitializationNode([], self::t('App\Foo'), self::anyParams()),
        ];
        yield 'initialization with parameters' => [
            'initialization(App\Foo::__construct(int, string))',
            new InitializationNode([], self::t('App\Foo'), new ParamListNode([self::t('int'), self::t('string')])),
        ];
        yield 'initialization with modifier and wildcard parameter' => [
            'initialization(protected App\Foo::__construct(*, ..))',
            new InitializationNode(['protected'], self::t('App\Foo'), new ParamListNode([new WildcardParamNode()], true)),
        ];
        yield 'initialization shorthand' => ['initialization(App\Foo+)', new InitializationNode([], self::t('App\Foo', false, true), self::anyParams())];
        yield 'initialization shorthand without plus' => ['initialization(App\Foo)', new InitializationNode([], self::t('App\Foo'), self::anyParams())];
        yield 'initialization shorthand wildcard' => ['initialization(App\**+)', new InitializationNode([], self::t('App\**', false, true), self::anyParams())];
        yield 'adviceexecution' => ['adviceexecution()', new AdviceExecutionNode()];
        yield 'adviceexecution within' => ['adviceexecution() && within(App\Aspect)', new AndNode(new AdviceExecutionNode(), new WithinNode(self::t('App\Aspect')))];
        yield 'handler' => ['handler(RuntimeException)', new HandlerNode(self::t('RuntimeException'))];
        yield 'handler subtypes' => ['handler(App\Error+)', new HandlerNode(self::t('App\Error', false, true))];
        yield 'handler binding' => ['handler(App\**) && args(e)', new AndNode(new HandlerNode(self::t('App\**')), new ArgsNode(new ParamListNode([new BindingCandidateNode('e')])))];
        yield 'get typed' => ['get(int App\Foo::$count)', new GetNode(new FieldPatternNode([], self::t('int'), self::t('App\Foo'), 'count'))];
        yield 'get without type' => ['get(App\Foo::$count)', new GetNode(new FieldPatternNode([], null, self::t('App\Foo'), 'count'))];
        yield 'get any type' => ['get(* App\Foo::$count)', new GetNode(new FieldPatternNode([], null, self::t('App\Foo'), 'count'))];
        yield 'set wildcards' => ['set(* App\**::$*)', new SetNode(new FieldPatternNode([], null, self::t('App\**'), '*'))];
        yield 'get modifiers nullable and plus' => [
            'get(public ?string App\Dto+::$name)',
            new GetNode(new FieldPatternNode(['public'], self::t('string', true), self::t('App\Dto', false, true), 'name')),
        ];
        yield 'set several modifiers' => [
            'set(private static int App\Foo::$total)',
            new SetNode(new FieldPatternNode(['private', 'static'], self::t('int'), self::t('App\Foo'), 'total')),
        ];
        yield 'get readonly' => ['get(public readonly * App\Foo::$id)', new GetNode(new FieldPatternNode(['public', 'readonly'], null, self::t('App\Foo'), 'id'))];
        yield 'get name pattern' => ['get(* App\Foo::$user*Id)', new GetNode(new FieldPatternNode([], null, self::t('App\Foo'), 'user*Id'))];
        yield 'get class type' => ['get(App\Money App\Foo::$price)', new GetNode(new FieldPatternNode([], self::t('App\Money'), self::t('App\Foo'), 'price'))];
        yield 'get subtype type' => ['get(App\Money+ App\Foo::$price)', new GetNode(new FieldPatternNode([], self::t('App\Money', false, true), self::t('App\Foo'), 'price'))];
        yield 'get and set combined' => [
            'get(* A::$x) || set(* A::$x)',
            new OrNode(new GetNode(new FieldPatternNode([], null, self::t('A'), 'x')), new SetNode(new FieldPatternNode([], null, self::t('A'), 'x'))),
        ];
        yield 'designator names stay usable as method names' => [
            'execution(* App\Foo::get(..)) && execution(* App\Foo::set(..))',
            new AndNode(
                new ExecutionNode([], null, self::t('App\Foo'), 'get', self::anyParams()),
                new ExecutionNode([], null, self::t('App\Foo'), 'set', self::anyParams()),
            ),
        ];
        yield 'designator names stay usable as function names' => [
            'call(* if(..)) || call(* handler(..))',
            new OrNode(
                new CallNode(new ExecutionNode([], null, null, 'if', self::anyParams())),
                new CallNode(new ExecutionNode([], null, null, 'handler', self::anyParams())),
            ),
        ];
    }

    #[Test]
    #[DataProvider('valid')]
    public function parses(string $expression, NodeInterface $expected): void
    {
        self::assertEquals($expected, (new Parser())->parse($expression));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function syntaxErrors(): iterable
    {
        yield 'call empty' => ['call()', 5];
        yield 'call without return type' => ['call(foo(..))', 8];
        yield 'call missing parameters' => ['call(* Foo::bar)', 15];
        yield 'withincode unterminated' => ['withincode(* Foo::bar(..)', 25];
        yield 'withincode plus on name' => ['withincode(* bar+(..))', 16];
        yield '@this empty' => ['@this()', 6];
        yield '@this list' => ['@this(a, b)', 7];
        yield '@withincode empty' => ['@withincode()', 12];
        yield 'cflow empty' => ['cflow()', 6];
        yield 'cflow unterminated' => ['cflow(within(A)', 15];
        yield 'cflow trailing operator' => ['cflow(within(A) &&)', 18];
        yield 'cflowbelow word' => ['cflowbelow(x)', 11];
        yield 'if empty' => ['if()', 3];
        yield 'if member without parentheses' => ['if(Foo::bar)', 11];
        yield 'if member without name' => ['if(Foo::)', 8];
        yield 'if with arguments' => ['if(Foo::bar(x))', 12];
        yield 'if invalid class' => ['if(Fo*::bar())', 3];
        yield 'if invalid method' => ['if(Foo::b*r())', 8];
        yield 'initialization empty' => ['initialization()', 15];
        yield 'initialization not a constructor' => ['initialization(App\Foo::find(..))', 24];
        yield 'initialization unterminated parameters' => ['initialization(App\Foo::__construct(', 36];
        yield 'initialization modifier without constructor' => ['initialization(public App\Foo)', 22];
        yield 'initialization with return type' => ['initialization(* App\Foo::__construct(..))', 17];
        yield 'adviceexecution with argument' => ['adviceexecution(within(A))', 16];
        yield 'handler empty' => ['handler()', 8];
        yield 'handler nullable' => ['handler(?Foo)', 8];
        yield 'handler invalid pattern' => ['handler(Fo**)', 8];
        yield 'get empty' => ['get()', 4];
        yield 'get without declaring type' => ['get($count)', 4];
        yield 'get without double colon' => ['get(int Foo)', 11];
        yield 'get property without dollar' => ['get(int Foo::count)', 13];
        yield 'get lone dollar' => ['get(int Foo::$)', 13];
        yield 'set missing property' => ['set(int Foo::)', 13];
        yield 'set modifier only' => ['set(public)', 10];
        yield 'set trailing garbage' => ['set(int Foo::$x y)', 16];
        yield 'get invalid type' => ['get(F-o Foo::$x)', 5];
    }

    #[Test]
    #[DataProvider('syntaxErrors')]
    public function reportsSyntaxErrorsWithPosition(string $expression, int $position): void
    {
        try {
            (new Parser())->parse($expression);
            self::fail('Expected PointcutSyntaxException.');
        } catch (PointcutSyntaxException $e) {
            self::assertStringContainsString('at position '.$position.' ', $e->getMessage());
            self::assertStringContainsString($expression, $e->getMessage());
        }
    }

    #[Test]
    public function preinitializationIsUnsupportedBecausePhpHasNoSuchJoinPoint(): void
    {
        try {
            (new Parser())->parse('preinitialization(App\Foo)');
            self::fail('Expected UnsupportedPointcutDesignatorException.');
        } catch (UnsupportedPointcutDesignatorException $e) {
            self::assertStringContainsString('"preinitialization"', $e->getMessage());
            self::assertStringContainsString('PHP has no pre-initialization join point', $e->getMessage());
            self::assertStringContainsString('initialization(Type+)', $e->getMessage());
        }
    }

    #[Test]
    public function staticinitializationIsUnsupportedBecausePhpHasNoSuchJoinPoint(): void
    {
        try {
            (new Parser())->parse('within(A) || staticinitialization(App\Foo)');
            self::fail('Expected UnsupportedPointcutDesignatorException.');
        } catch (UnsupportedPointcutDesignatorException $e) {
            self::assertStringContainsString('"staticinitialization"', $e->getMessage());
            self::assertStringContainsString('PHP has no static initialization join point', $e->getMessage());
        }
    }

    #[Test]
    public function callAndTheOtherNewDesignatorsAreNoLongerUnsupported(): void
    {
        foreach (['call(* a(..))', 'withincode(* a(..))', 'cflow(within(A))', 'cflowbelow(within(A))', 'if(a())', 'initialization(A+)', 'adviceexecution()', 'handler(A)', 'get(* A::$a)', 'set(* A::$a)', '@this(A)', '@withincode(A)'] as $expression) {
            self::assertInstanceOf(NodeInterface::class, (new Parser())->parse($expression), $expression);
        }
    }

    #[Test]
    public function everyDesignatorTheParserKnowsIsAKeywordOfTheLexer(): void
    {
        $designators = [
            'execution', 'within', 'this', 'target', 'args', 'service', '@annotation', '@within', '@target', '@args',
            'call', 'withincode', '@withincode', '@this', 'cflow', 'cflowbelow', 'if', 'initialization',
            'adviceexecution', 'handler', 'get', 'set', 'preinitialization', 'staticinitialization',
        ];

        self::assertEqualsCanonicalizing($designators, Lexer::DESIGNATORS);
    }
}
