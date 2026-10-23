<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Pointcut;

use AOP\Exception\PointcutSyntaxException;
use AOP\JoinPoint\JoinPointKind;
use AOP\Pointcut\Expression\Ast\WithincodeNode;
use AOP\Pointcut\Expression\Parser;
use AOP\Tests\Fixtures\Pointcut\Shadow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

abstract class ModsSubject
{
    public function pub(): void
    {
    }

    protected function prot(): void
    {
    }

    private function priv(): void // @phpstan-ignore method.unused
    {
    }

    public static function stat(): void
    {
    }

    private static function privStat(): void // @phpstan-ignore method.unused
    {
    }

    final public function fin(): void
    {
    }

    abstract public function abs(): void;

    public static int $counter = 0;
    public int $plain = 0;
    private static string $secret = ''; // @phpstan-ignore property.onlyWritten
}

function modsFunction(): void
{
}

class ModsBare
{
}

class ModsCtor
{
    public function __construct()
    {
    }
}

final class MethodModifiersTest extends TestCase
{
    private const C = 'AOP\Tests\Unit\Pointcut\ModsSubject';

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function methods(): iterable
    {
        $cases = [
            'public' => ['pub' => true, 'prot' => false, 'priv' => false, 'stat' => true, 'privStat' => false, 'fin' => true, 'abs' => true],
            'protected' => ['pub' => false, 'prot' => true, 'priv' => false, 'stat' => false, 'privStat' => false, 'fin' => false, 'abs' => false],
            'private' => ['pub' => false, 'prot' => false, 'priv' => true, 'stat' => false, 'privStat' => true, 'fin' => false, 'abs' => false],
            'static' => ['pub' => false, 'prot' => false, 'priv' => false, 'stat' => true, 'privStat' => true, 'fin' => false, 'abs' => false],
            'final' => ['pub' => false, 'prot' => false, 'priv' => false, 'stat' => false, 'privStat' => false, 'fin' => true, 'abs' => false],
            'abstract' => ['pub' => false, 'prot' => false, 'priv' => false, 'stat' => false, 'privStat' => false, 'fin' => false, 'abs' => true],
            '!static' => ['pub' => true, 'prot' => true, 'priv' => true, 'stat' => false, 'privStat' => false, 'fin' => true, 'abs' => true],
            '!final' => ['pub' => true, 'prot' => true, 'priv' => true, 'stat' => true, 'privStat' => true, 'fin' => false, 'abs' => true],
            '!private' => ['pub' => true, 'prot' => true, 'priv' => false, 'stat' => true, 'privStat' => false, 'fin' => true, 'abs' => true],
            '!public' => ['pub' => false, 'prot' => true, 'priv' => true, 'stat' => false, 'privStat' => true, 'fin' => false, 'abs' => false],
            '!abstract' => ['pub' => true, 'prot' => true, 'priv' => true, 'stat' => true, 'privStat' => true, 'fin' => true, 'abs' => false],
            'private static' => ['pub' => false, 'prot' => false, 'priv' => false, 'stat' => false, 'privStat' => true, 'fin' => false, 'abs' => false],
            'static private' => ['pub' => false, 'prot' => false, 'priv' => false, 'stat' => false, 'privStat' => true, 'fin' => false, 'abs' => false],
            'public !static' => ['pub' => true, 'prot' => false, 'priv' => false, 'stat' => false, 'privStat' => false, 'fin' => true, 'abs' => true],
            'public final' => ['pub' => false, 'prot' => false, 'priv' => false, 'stat' => false, 'privStat' => false, 'fin' => true, 'abs' => false],
            '!private !static' => ['pub' => true, 'prot' => true, 'priv' => false, 'stat' => false, 'privStat' => false, 'fin' => true, 'abs' => true],
        ];
        foreach ($cases as $modifiers => $expected) {
            foreach ($expected as $method => $matches) {
                yield $modifiers.' / '.$method => [$modifiers, $method, $matches];
            }
        }
    }

    #[Test]
    #[DataProvider('methods')]
    public function methodModifiers(string $modifiers, string $method, bool $expected): void
    {
        $pointcut = Shadow::compile(sprintf('execution(%s * %s::*(..))', $modifiers, self::C));

        self::assertSame($expected, Shadow::matches($pointcut, Shadow::method(ModsSubject::class, $method)));
    }

    #[Test]
    public function aPrivateStaticPatternOverANamespaceGlob(): void
    {
        $pointcut = Shadow::compile('execution(private static * AOP\Tests\Unit\**::*(..))');

        self::assertTrue(Shadow::matches($pointcut, Shadow::method(ModsSubject::class, 'privStat')));
        self::assertFalse(Shadow::matches($pointcut, Shadow::method(ModsSubject::class, 'stat')));
    }

    #[Test]
    public function callAndWithincodeAndInitializationAcceptThemToo(): void
    {
        self::assertTrue(Shadow::matches(Shadow::compile('call(private static * '.self::C.'::*(..))'), Shadow::method(ModsSubject::class, 'privStat', JoinPointKind::METHOD_CALL)));
        self::assertFalse(Shadow::matches(Shadow::compile('call(!static * '.self::C.'::*(..))'), Shadow::method(ModsSubject::class, 'privStat', JoinPointKind::METHOD_CALL)));
        $node = (new Parser())->parse('withincode(private !static * *::*(..))');
        self::assertInstanceOf(WithincodeNode::class, $node);
        self::assertSame(['private', '!static'], $node->pattern->modifiers);
        $init = Shadow::compile('initialization(public !static AOP\Tests\Unit\Pointcut\ModsCtor::__construct(..))');
        self::assertTrue(Shadow::matches($init, Shadow::constructor(ModsCtor::class)));
        self::assertFalse(Shadow::matches(Shadow::compile('initialization(private AOP\Tests\Unit\Pointcut\ModsCtor::__construct(..))'), Shadow::constructor(ModsCtor::class)));
    }

    #[Test]
    public function functionsAreNonStaticPublicNonFinal(): void
    {
        $ctx = Shadow::function('AOP\Tests\Unit\Pointcut\modsFunction');

        self::assertTrue(Shadow::matches(Shadow::compile('execution(!static * *(..))'), $ctx));
        self::assertTrue(Shadow::matches(Shadow::compile('execution(public !final !static * AOP\Tests\Unit\Pointcut\mods*(..))'), $ctx));
        foreach (['static', 'private', 'protected', 'final', 'abstract'] as $modifier) {
            self::assertFalse(Shadow::matches(Shadow::compile('execution('.$modifier.' * *(..))'), $ctx), $modifier);
        }
    }

    #[Test]
    public function anImplicitConstructorIsPublic(): void
    {
        $bare = Shadow::initialization(ModsBare::class);

        self::assertTrue(Shadow::matches(Shadow::compile('initialization(public '.ModsBare::class.'::__construct(..))'), $bare));
        self::assertTrue(Shadow::matches(Shadow::compile('initialization(!static '.ModsBare::class.'::__construct(..))'), $bare));
        self::assertFalse(Shadow::matches(Shadow::compile('initialization(protected '.ModsBare::class.'::__construct(..))'), $bare));
        self::assertFalse(Shadow::matches(Shadow::compile('initialization(!public '.ModsBare::class.'::__construct(..))'), $bare));
        self::assertFalse(Shadow::matches(Shadow::compile('initialization(final '.ModsBare::class.'::__construct(..))'), $bare));
    }

    #[Test]
    public function aMethodMayStillBeNamedLikeAModifier(): void
    {
        Shadow::compile('execution(* *::static(..))');
        Shadow::compile('execution(public * final(..))');
        $this->addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unknownModifiers(): iterable
    {
        yield 'misspelled' => ['execution(pubic * Foo::bar(..))', 'pubic'];
        yield 'misspelled with return type' => ['execution(abstrct string Foo::bar(..))', 'abstrct'];
        yield 'misspelled nullable return' => ['execution(statik ?string Foo::bar(..))', 'statik'];
        yield 'negated unknown' => ['execution(!readonly * Foo::bar(..))', 'readonly'];
        yield 'before a constructor pattern' => ['execution(pubic Foo::__construct(..))', 'pubic'];
        yield 'in call' => ['call(privat * Foo::bar(..))', 'privat'];
        yield 'in withincode' => ['withincode(readonly * Foo::bar(..))', 'readonly'];
        yield 'in initialization' => ['initialization(pubic Foo::__construct(..))', 'pubic'];
        yield 'in a field' => ['get(pubic string Foo::$x)', 'pubic'];
        yield 'negated unknown field modifier' => ['get(!final string Foo::$x)', 'final'];
    }

    #[Test]
    #[DataProvider('unknownModifiers')]
    public function unknownModifiersGetAClearError(string $expression, string $word): void
    {
        try {
            Shadow::compile($expression);
            self::fail('Expected PointcutSyntaxException');
        } catch (PointcutSyntaxException $e) {
            self::assertStringContainsString(sprintf('Unknown modifier "%s"', $word), $e->getMessage());
            self::assertStringContainsString('public', $e->getMessage());
            self::assertStringContainsString('"!"', $e->getMessage());
        }
    }

    #[Test]
    public function negationNeedsAModifier(): void
    {
        $this->expectException(PointcutSyntaxException::class);
        Shadow::compile('execution(! * Foo::bar(..))');
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function fields(): iterable
    {
        yield 'static' => ['static', 'counter', true];
        yield 'static / not static' => ['static', 'plain', false];
        yield '!static' => ['!static', 'plain', true];
        yield '!static / static' => ['!static', 'counter', false];
        yield 'public !static' => ['public !static', 'plain', true];
        yield 'public !static / static' => ['public !static', 'counter', false];
        yield '!private' => ['!private', 'secret', false];
        yield '!private / public' => ['!private', 'plain', true];
        yield 'private static' => ['private static', 'secret', true];
        yield '!readonly' => ['!readonly', 'plain', true];
        yield 'readonly' => ['readonly', 'plain', false];
    }

    #[Test]
    #[DataProvider('fields')]
    public function fieldModifiersAcceptNegation(string $modifiers, string $property, bool $expected): void
    {
        $pointcut = Shadow::compile(sprintf('get(%s * %s::$%s)', $modifiers, self::C, $property));
        $ctx = Shadow::field(ModsSubject::class, $property);

        self::assertSame($expected, Shadow::matches($pointcut, $ctx));
    }
}
