<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Pointcut\Expression;

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
use AOP\Pointcut\Expression\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ParserTest extends TestCase
{
    private static function t(string $p, bool $nullable = false, bool $sub = false): TypePatternNode
    {
        return new TypePatternNode($p, $nullable, $sub);
    }

    /**
     * @param list<TypePatternNode|WildcardParamNode|BindingCandidateNode> $params
     */
    private static function pl(array $params = [], bool $ellipsis = false): ParamListNode
    {
        return new ParamListNode($params, $ellipsis);
    }

    /**
     * @return iterable<string, array{string, NodeInterface}>
     */
    public static function valid(): iterable
    {
        yield 'execution glob' => ['execution(* App\Service\*::get*(..))', new ExecutionNode(
            null,
            null,
            self::t('App\Service\*'),
            'get*',
            self::pl([], true),
        )];
        yield 'execution modifier nullable return, ** and params' => [
            'execution(public ?string App\Service\**::*(int, ..))',
            new ExecutionNode('public', self::t('string', true), self::t('App\Service\**'), '*', self::pl([self::t('int')], true)),
        ];
        yield 'execution any any' => ['execution(* *(..))', new ExecutionNode(null, null, null, '*', self::pl([], true))];
        yield 'execution subtype return' => ['execution(App\Entity\User+ *(..))', new ExecutionNode(
            null,
            self::t('App\Entity\User', false, true),
            null,
            '*',
            self::pl([], true),
        )];
        yield 'execution protected, leading backslash, empty params' => [
            'execution(protected \App\Foo find())',
            new ExecutionNode('protected', self::t('App\Foo'), null, 'find', self::pl()),
        ];
        yield 'execution subtype declaring type' => [
            'execution(* App\Contract\Repo+::*(*, ?int, string))',
            new ExecutionNode(null, null, self::t('App\Contract\Repo', false, true), '*', self::pl([
                new WildcardParamNode(),
                self::t('int', true),
                self::t('string'),
            ])),
        ];
        yield 'execution: namespaced function' => [
            'execution(* App\Util\fmt*(..))',
            new ExecutionNode(null, null, null, 'App\Util\fmt*', self::pl([], true)),
        ];
        yield 'execution: leading backslash on function' => [
            'execution(string \App\Util\fmt(string))',
            new ExecutionNode(null, self::t('string'), null, 'App\Util\fmt', self::pl([self::t('string')])),
        ];
        yield 'execution: internal function glob' => ['execution(* str_*(..))', new ExecutionNode(null, null, null, 'str_*', self::pl([], true))];
        yield 'execution: constructor' => [
            'execution(* App\Service\User::__construct(..))',
            new ExecutionNode(null, null, self::t('App\Service\User'), '__construct', self::pl([], true)),
        ];
        yield 'execution: method named like a modifier' => [
            'execution(* public(..))',
            new ExecutionNode(null, null, null, 'public', self::pl([], true)),
        ];
        yield 'execution: method named like a designator' => [
            'execution(* target(..))',
            new ExecutionNode(null, null, null, 'target', self::pl([], true)),
        ];
        yield 'execution: bare identifiers are types' => [
            'execution(* *(Foo))',
            new ExecutionNode(null, null, null, '*', self::pl([self::t('Foo')])),
        ];
        yield 'within and not annotation' => [
            'within(App\Service\**) && !@annotation(App\Attribute\NoLog)',
            new AndNode(
                new WithinNode(self::t('App\Service\**')),
                new NotNode(new AnnotationNode(self::t('App\Attribute\NoLog'))),
            ),
        ];
        yield 'within subtype' => ['within(App\Foo+)', new WithinNode(self::t('App\Foo', false, true))];
        yield 'within bare identifier is a type' => ['within(Foo)', new WithinNode(self::t('Foo'))];
        yield 'or lower than and' => [
            'within(A) || within(B) && within(C)',
            new OrNode(new WithinNode(self::t('A')), new AndNode(new WithinNode(self::t('B')), new WithinNode(self::t('C')))),
        ];
        yield 'and is left associative' => [
            'within(A) && within(B) && within(C)',
            new AndNode(new AndNode(new WithinNode(self::t('A')), new WithinNode(self::t('B'))), new WithinNode(self::t('C'))),
        ];
        yield 'or is left associative' => [
            'within(A) || within(B) || within(C)',
            new OrNode(new OrNode(new WithinNode(self::t('A')), new WithinNode(self::t('B'))), new WithinNode(self::t('C'))),
        ];
        yield 'nested parens override precedence' => [
            '(within(A) || within(B)) && !(within(C))',
            new AndNode(
                new OrNode(new WithinNode(self::t('A')), new WithinNode(self::t('B'))),
                new NotNode(new WithinNode(self::t('C'))),
            ),
        ];
        yield 'double negation' => ['!!within(A)', new NotNode(new NotNode(new WithinNode(self::t('A'))))];
        yield 'args binding then ellipsis' => ['args(id, ..)', new ArgsNode(self::pl([new BindingCandidateNode('id')], true))];
        yield 'args type and wildcard' => [
            'args(string, *)',
            new ArgsNode(self::pl([new BindingCandidateNode('string'), new WildcardParamNode()])),
        ];
        yield 'args qualified is type' => ['args(App\Foo, ?int)', new ArgsNode(self::pl([self::t('App\Foo'), self::t('int', true)]))];
        yield 'args only ellipsis' => ['args(..)', new ArgsNode(self::pl([], true))];
        yield 'args empty' => ['args()', new ArgsNode(self::pl())];
        yield 'args keyword name as binding' => ['args(target)', new ArgsNode(self::pl([new BindingCandidateNode('target')]))];
        yield '@args' => ['@args(a, App\Attr, ..)', new AtArgsNode(self::pl([new BindingCandidateNode('a'), self::t('App\Attr')], true))];
        yield '@annotation binding' => ['@annotation(audit)', new AnnotationNode(new BindingCandidateNode('audit'))];
        yield '@within' => ['@within(App\Attr)', new AtWithinNode(self::t('App\Attr'))];
        yield '@within binding' => ['@within(attr)', new AtWithinNode(new BindingCandidateNode('attr'))];
        yield '@target' => ['@target(attr)', new AtTargetNode(new BindingCandidateNode('attr'))];
        yield 'service' => ['service(app.*_service)', new ServiceNode('app.*_service')];
        yield 'service dashes' => ['service( my-service )', new ServiceNode('my-service')];
        yield 'service FQCN id' => ['service(App\Service\StringService)', new ServiceNode('App\Service\StringService')];
        yield 'this type' => ['this(App\Contract\Foo)', new ThisNode(self::t('App\Contract\Foo'))];
        yield 'this binding' => ['this(proxy)', new ThisNode(new BindingCandidateNode('proxy'))];
        yield 'target binding' => ['target(t)', new TargetNode(new BindingCandidateNode('t'))];
        yield 'local reference' => ['serviceLayer()', new ReferenceNode(null, 'serviceLayer')];
        yield 'foreign reference' => [
            'App\Aspect\CommonPointcuts::serviceLayer()',
            new ReferenceNode('App\Aspect\CommonPointcuts', 'serviceLayer'),
        ];
        yield 'reference combined' => [
            '!serviceLayer() && Foo::bar()',
            new AndNode(new NotNode(new ReferenceNode(null, 'serviceLayer')), new ReferenceNode('Foo', 'bar')),
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
        yield 'unterminated execution' => ['execution(* foo(', 16];
        yield 'dangling and' => ['within(A) &&', 12];
        yield 'leading or' => ['|| within(A)', 0];
        yield 'empty' => ['', 0];
        yield 'ellipsis not last' => ['args(.., x)', 7];
        yield 'ellipsis twice' => ['args(.., ..)', 7];
        yield 'trailing comma' => ['args(a,)', 7];
        yield 'missing close paren' => ['(within(A)', 10];
        yield 'trailing garbage' => ['within(A) within(B)', 10];
        yield 'partial double star' => ['within(Se**)', 7];
        yield 'partial double star prefix' => ['within(App\**Service)', 7];
        yield 'empty segment' => ['within(App\\\\Foo)', 7];
        yield 'trailing backslash' => ['within(App\Foo\)', 7];
        yield 'within nullable' => ['within(?Foo)', 7];
        yield 'plus on name' => ['execution(* foo+(..))', 15];
        yield 'qualified method name with declaring type' => ['execution(* Foo::App\foo(..))', 17];
        yield 'function name with empty segment' => ['execution(* App\\\\foo(..))', 12];
        yield 'function name with trailing backslash' => ['execution(* App\foo\(..))', 12];
        yield 'missing return pattern' => ['execution(foo(..))', 13];
        yield 'empty this' => ['this()', 5];
        yield 'service empty' => ['service()', 8];
        yield 'execution missing params' => ['execution(* foo)', 15];
        yield 'bare word' => ['foo', 0];
        yield 'reference with args' => ['Foo::bar(x)', 9];
        yield 'reference with wildcard' => ['fo*()', 0];
        yield 'annotation list' => ['@annotation(a, b)', 13];
        yield 'at prefixed type' => ['within(@Foo)', 7];
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

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unsupported(): iterable
    {
        yield 'preinitialization' => ['preinitialization(App\\Foo)', 'preinitialization'];
        yield 'staticinitialization' => ['within(A) && staticinitialization(App\\Foo)', 'staticinitialization'];
        yield 'unknown with args' => ['frobnicate(x)', 'frobnicate'];
    }

    #[Test]
    #[DataProvider('unsupported')]
    public function rejectsUnsupportedDesignators(string $expression, string $name): void
    {
        $this->expectException(UnsupportedPointcutDesignatorException::class);
        $this->expectExceptionMessage('"'.$name.'"');

        (new Parser())->parse($expression);
    }
}
