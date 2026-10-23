<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Pointcut;

use AOP\JoinPoint\JoinPointKind;
use AOP\Pointcut\StaticMatchContext;
use AOP\Tests\Fixtures\Pointcut\Shadow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class CtorBase
{
    public function __construct(int $x = 0)
    {
    }

    public function run(): void
    {
    }
}

final class CtorOwn extends CtorBase
{
    public function __construct()
    {
        parent::__construct();
    }

    public function run(): void
    {
    }
}

final class CtorInherited extends CtorBase
{
}

class CtorBare
{
}

final class CtorBareChild extends CtorBare
{
}

/**
 * Constructors are not overridden (AspectJ): a pattern naming Base matches the constructor declared by Base only.
 */
final class ConstructorsDoNotOverrideTest extends TestCase
{
    private const BASE = 'AOP\Tests\Unit\Pointcut\CtorBase';
    private const OWN = 'AOP\Tests\Unit\Pointcut\CtorOwn';
    private const INHERITED = 'AOP\Tests\Unit\Pointcut\CtorInherited';

    /**
     * @return iterable<string, array{string, StaticMatchContext, bool}>
     */
    public static function executions(): iterable
    {
        foreach (['execution(* '.self::BASE.'::__construct(..))', 'execution('.self::BASE.'::__construct(..))'] as $i => $expression) {
            yield $i.' Base ctor, own object' => [$expression, Shadow::constructor(CtorBase::class), true];
            yield $i.' subclass with its own ctor' => [$expression, Shadow::constructor(CtorOwn::class), false];
            yield $i.' parent::__construct() of the subclass, once' => [$expression, Shadow::inheritedConstructor(CtorOwn::class, CtorBase::class), true];
            yield $i.' subclass inheriting the ctor' => [$expression, Shadow::constructor(CtorInherited::class), true];
        }
        $plus = 'execution('.self::BASE.'+::__construct(..))';
        yield 'plus: Base ctor' => [$plus, Shadow::constructor(CtorBase::class), true];
        yield 'plus: subclass with its own ctor' => [$plus, Shadow::constructor(CtorOwn::class), true];
        yield 'plus: inherited' => [$plus, Shadow::constructor(CtorInherited::class), true];

        $own = 'execution('.self::OWN.'::__construct(..))';
        yield 'subclass pattern: its own ctor' => [$own, Shadow::constructor(CtorOwn::class), true];
        yield 'subclass pattern: not the parent:: execution of Base' => [$own, Shadow::inheritedConstructor(CtorOwn::class, CtorBase::class), false];
        yield 'subclass pattern: Base ctor of a Base object' => [$own, Shadow::constructor(CtorBase::class), false];

        $inherited = 'execution('.self::INHERITED.'::__construct(..))';
        yield 'inheriting subclass is matched by its own name' => [$inherited, Shadow::constructor(CtorInherited::class), true];
        yield 'Base object is not' => [$inherited, Shadow::constructor(CtorBase::class), false];

        yield 'call: Base ctor' => ['call('.self::BASE.'::__construct(..))', Shadow::constructor(CtorBase::class, JoinPointKind::CONSTRUCTOR_CALL), true];
        yield 'call: not the subclass ctor' => ['call('.self::BASE.'::__construct(..))', Shadow::constructor(CtorOwn::class, JoinPointKind::CONSTRUCTOR_CALL), false];
        yield 'call plus: subclass ctor' => ['call('.self::BASE.'+::__construct(..))', Shadow::constructor(CtorOwn::class, JoinPointKind::CONSTRUCTOR_CALL), true];
        yield 'call: inherited ctor' => ['call('.self::BASE.'::__construct(..))', Shadow::constructor(CtorInherited::class, JoinPointKind::CONSTRUCTOR_CALL), true];

        // initialization() is decided on the outermost constructor; statically the same rule applies
        yield 'initialization: Base ctor' => ['initialization('.self::BASE.'::__construct(..))', Shadow::constructor(CtorBase::class), true];
        yield 'initialization: not the subclass ctor' => ['initialization('.self::BASE.'::__construct(..))', Shadow::constructor(CtorOwn::class), false];
        yield 'initialization plus: subclass ctor' => ['initialization('.self::BASE.'+)', Shadow::constructor(CtorOwn::class), true];
        yield 'initialization: constructor-less class' => ['initialization('.CtorBare::class.'::__construct(..))', Shadow::initialization(CtorBare::class), true];
        yield 'initialization: constructor-less subclass is not Base' => ['initialization('.CtorBare::class.'::__construct(..))', Shadow::initialization(CtorBareChild::class), false];
        yield 'initialization plus: constructor-less subclass' => ['initialization('.CtorBare::class.'+)', Shadow::initialization(CtorBareChild::class), true];
    }

    #[Test]
    #[DataProvider('executions')]
    public function matchesOnlyTheConstructorDeclaredByTheType(string $expression, StaticMatchContext $ctx, bool $expected): void
    {
        self::assertSame($expected, Shadow::matches(Shadow::compile($expression), $ctx));
    }

    #[Test]
    public function withincodeFollowsTheSameRule(): void
    {
        $base = Shadow::compile('withincode('.self::BASE.'::__construct(..))');
        $plus = Shadow::compile('withincode('.self::BASE.'+::__construct(..))');
        $callFrom = static fn (string $class): StaticMatchContext => Shadow::method(CtorBase::class, 'run', JoinPointKind::METHOD_CALL, Shadow::caller($class, '__construct'));

        self::assertTrue(Shadow::matches($base, $callFrom(CtorBase::class)));
        self::assertFalse(Shadow::matches($base, $callFrom(CtorOwn::class)));
        self::assertTrue(Shadow::matches($plus, $callFrom(CtorOwn::class)));
    }

    #[Test]
    public function regularMethodsKeepMatchingOverridesThroughTheDeclaringType(): void
    {
        $pointcut = Shadow::compile('execution(* '.self::BASE.'::run(..))');

        self::assertTrue(Shadow::matches($pointcut, Shadow::method(CtorBase::class, 'run')));
        self::assertTrue(Shadow::matches($pointcut, Shadow::method(CtorOwn::class, 'run')));
    }
}
