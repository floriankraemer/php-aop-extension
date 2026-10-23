<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Pointcut\Pattern;

use AOP\Pointcut\Expression\Ast\BindingCandidateNode;
use AOP\Pointcut\Expression\Ast\ParamListNode;
use AOP\Pointcut\Expression\Ast\TypePatternNode;
use AOP\Pointcut\Expression\Ast\WildcardParamNode;
use AOP\Pointcut\Pattern\ParameterPattern;
use AOP\Tests\Fixtures\Hierarchy\UserRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ParameterPatternTest extends TestCase
{
    private const FX = 'AOP\Tests\Fixtures\Hierarchy\\';

    private static function t(string $p, bool $nullable = false, bool $plus = false): TypePatternNode
    {
        return new TypePatternNode($p, $nullable, $plus);
    }

    /**
     * @return iterable<string, array{ParamListNode, string, bool}>
     */
    public static function cases(): iterable
    {
        $any = new WildcardParamNode();

        yield '() on no params' => [new ParamListNode([]), 'noParams', true];
        yield '() on params' => [new ParamListNode([]), 'twoStrings', false];
        yield '(..) on none' => [new ParamListNode([], true), 'noParams', true];
        yield '(..) on many' => [new ParamListNode([], true), 'rename', true];
        yield '(*) on one' => [new ParamListNode([$any]), 'audited', true];
        yield '(*) on none' => [new ParamListNode([$any]), 'noParams', false];
        yield '(*) on two' => [new ParamListNode([$any]), 'twoStrings', false];
        yield '(*,*) on two' => [new ParamListNode([$any, $any]), 'twoStrings', true];
        yield '(string) on string' => [new ParamListNode([self::t('string')]), 'audited', true];
        yield '(int) on string' => [new ParamListNode([self::t('int')]), 'audited', false];
        yield '(int, ..)' => [new ParamListNode([self::t('int')], true), 'intThenAny', true];
        yield '(int, ..) wrong first' => [new ParamListNode([self::t('int')], true), 'twoStrings', false];
        yield '(int, ..) on none' => [new ParamListNode([self::t('int')], true), 'noParams', false];
        yield '(*, string)' => [new ParamListNode([$any, self::t('string')]), 'twoStrings', true];
        yield '(*, string) wrong arity' => [new ParamListNode([$any, self::t('string')]), 'intThenAny', false];
        yield '(*, int) wrong second' => [new ParamListNode([$any, self::t('int')]), 'intThenAny', false];
        yield '(int, string, array)' => [new ParamListNode([self::t('int'), self::t('string'), self::t('array')]), 'intThenAny', true];
        yield 'binding candidate is a type name' => [new ParamListNode([new BindingCandidateNode('string')]), 'audited', true];
        yield 'binding candidate type name mismatch' => [new ParamListNode([new BindingCandidateNode('int')]), 'audited', false];
        // nullable: `?int` declared never matches the plain `int` pattern (documented rule)
        yield 'nullable declared vs plain pattern' => [new ParamListNode([self::t('int'), $any, $any, $any]), 'rename', false];
        yield 'nullable declared vs ? pattern' => [new ParamListNode([self::t('int', true), $any, $any, $any]), 'rename', true];
        yield '? pattern vs non-nullable declared' => [new ParamListNode([self::t('string', true)]), 'audited', false];
        yield 'union matches only star' => [new ParamListNode([$any, self::t('int'), $any, $any]), 'rename', false];
        yield 'union vs star' => [new ParamListNode([$any, $any, $any, $any]), 'rename', true];
        yield 'intersection matches only star' => [new ParamListNode([$any, $any, $any, self::t('Countable')]), 'rename', false];
        yield 'untyped matches only star' => [new ParamListNode([$any, $any, self::t('mixed'), $any]), 'rename', false];
        yield 'class type exact' => [new ParamListNode([self::t(self::FX.'UserRepository'), $any, $any]), 'repo', true];
        yield 'class type glob' => [new ParamListNode([self::t(self::FX.'*Repository'), $any, $any]), 'repo', true];
        yield 'class type with plus on interface' => [new ParamListNode([$any, self::t(self::FX.'ReadableInterface', false, true), $any]), 'repo', true];
        yield 'class type without plus on interface' => [new ParamListNode([$any, self::t(self::FX.'ReadableInterface'), $any]), 'repo', false];
        yield 'nullable class with plus' => [new ParamListNode([$any, $any, self::t(self::FX.'RepositoryInterface', true, true)]), 'repo', true];
        yield 'nullable class missing ?' => [new ParamListNode([$any, $any, self::t(self::FX.'AbstractRepository')]), 'repo', false];
    }

    #[Test]
    #[DataProvider('cases')]
    public function matchesMethodParameters(ParamListNode $list, string $method, bool $expected): void
    {
        $result = ParameterPattern::fromNode($list)->matches(new \ReflectionMethod(UserRepository::class, $method));

        self::assertSame($expected, $result);
    }
}
