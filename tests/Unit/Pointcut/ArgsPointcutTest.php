<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Pointcut;

use AOP\JoinPoint\FieldSignature;
use AOP\JoinPoint\JoinPointData;
use AOP\JoinPoint\JoinPointKind;
use AOP\JoinPoint\MethodInvocation;
use AOP\JoinPoint\StaticPart;
use AOP\Pointcut\BindingKind;
use AOP\Pointcut\Expression\PointcutExpressionCompiler;
use AOP\Pointcut\PointcutInterface;
use AOP\Pointcut\RuntimeMatchContext;
use AOP\Pointcut\StaticMatchContext;
use AOP\Tests\Fixtures\Hierarchy\Plain;
use AOP\Tests\Fixtures\Hierarchy\RepositoryInterface;
use AOP\Tests\Fixtures\Hierarchy\UserRepository;
use AOP\Tests\Fixtures\Jp;
use AOP\Tests\Fixtures\Pointcut\Reflect;
use AOP\Tests\Fixtures\Service\UserService;
use AOP\Tests\Fixtures\Targets\KindShapes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ArgsPointcutTest extends TestCase
{
    /**
     * @param array<int|string, string|null> $names
     */
    private function pc(string $expression, array $names = []): PointcutInterface
    {
        return (new PointcutExpressionCompiler())->compile($expression, $names);
    }

    /**
     * @param class-string $class
     */
    private function static(PointcutInterface $pc, string $class, string $method): bool
    {
        $ctx = Jp::ctx($class, $method);

        return $pc->matchesClass($ctx->targetClass, $ctx) && $pc->matchesMethod(new \ReflectionMethod($class, $method), $ctx);
    }

    /**
     * @param list<mixed> $args
     */
    private function runtime(PointcutInterface $pc, array $args, string $method = 'find'): RuntimeMatchContext
    {
        $ctx = Jp::runtime(new UserRepository(), $method, $args);
        $pc->matchesRuntime($ctx);

        return $ctx;
    }

    /**
     * @param list<mixed> $args
     */
    private function argsMatch(string $expression, array $args, string $method = 'find'): bool
    {
        $pc = $this->pc($expression);
        $ctx = Jp::runtime(new UserRepository(), $method, $args);

        return $pc->matchesRuntime($ctx);
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function arityCases(): iterable
    {
        yield 'exact ok' => ['args(string, string)', 'twoStrings', true];
        yield 'exact too few' => ['args(string)', 'twoStrings', false];
        yield 'exact too many' => ['args(string, string, string)', 'twoStrings', false];
        yield 'none' => ['args()', 'noParams', true];
        yield 'none vs one' => ['args()', 'find', false];
        yield 'ellipsis alone' => ['args(..)', 'twoStrings', true];
        yield 'at least ok' => ['args(string, ..)', 'twoStrings', true];
        yield 'at least too many' => ['args(*, *, *, ..)', 'twoStrings', false];
        // Proxies always forward every declared parameter (defaults filled in), so optional ones count.
        yield 'optional param is always forwarded' => ['args(int, string)', 'intThenAny', false];
        yield 'all params including optional' => ['args(int, string, array)', 'intThenAny', true];
        yield 'single wildcard vs optional tail' => ['args(*)', 'optionalTail', false];
        yield 'ellipsis covers optional tail' => ['args(int, ..)', 'intThenAny', true];
        yield 'ellipsis over declared count' => ['args(*, *, *, *, ..)', 'intThenAny', false];
    }

    #[Test]
    #[DataProvider('arityCases')]
    public function staticPartPrunesByArity(string $expression, string $method, bool $expected): void
    {
        self::assertSame($expected, $this->static($this->pc($expression), UserRepository::class, $method));
    }

    #[Test]
    public function bindingMustCoverOptionalParametersToo(): void
    {
        self::assertFalse($this->static($this->pc('args(n)', ['n' => 'string']), UserRepository::class, 'optionalTail'));
        self::assertTrue($this->static($this->pc('args(n, *)', ['n' => 'string']), UserRepository::class, 'optionalTail'));
        self::assertTrue($this->static($this->pc('args(n, ..)', ['n' => 'string']), UserRepository::class, 'optionalTail'));
    }

    #[Test]
    public function variadicSatisfiesAnyRemainingCount(): void
    {
        foreach (['args(int)', 'args(int, string)', 'args(int, string, string, string)', 'args(int, ..)'] as $expression) {
            self::assertTrue($this->static($this->pc($expression), UserService::class, 'findUser'), $expression);
        }
        self::assertFalse($this->static($this->pc('args()'), UserService::class, 'findUser'));
    }

    #[Test]
    public function staticPartPrunesIncompatibleDeclaredTypes(): void
    {
        self::assertFalse($this->static($this->pc('args(AOP\Tests\Fixtures\Hierarchy\Plain)'), UserRepository::class, 'find'));
        self::assertFalse($this->static($this->pc('args(string)'), UserRepository::class, 'find'));
        self::assertTrue($this->static($this->pc('args(int)'), UserRepository::class, 'find'));
        self::assertFalse($this->static($this->pc('args(int, *, *)'), UserRepository::class, 'repo'));
    }

    #[Test]
    public function staticPartKeepsUntypedMixedAndUnionDeclaredTypes(): void
    {
        // rename(?int, int|string, untyped, Countable&Traversable)
        self::assertTrue($this->static($this->pc('args(int, bool, App\Foo, App\Bar)'), UserRepository::class, 'rename'));
        self::assertTrue($this->static($this->pc('args(?int, string, int, ..)'), UserRepository::class, 'rename'));
    }

    #[Test]
    public function staticPartPrunesUsingVariadicDeclaredType(): void
    {
        self::assertFalse($this->static($this->pc('args(int, string, int)'), UserService::class, 'findUser'));
        self::assertTrue($this->static($this->pc('args(int, string, string)'), UserService::class, 'findUser'));
    }

    #[Test]
    public function bindingWithRestrictionPrunesStatically(): void
    {
        self::assertFalse($this->static($this->pc('args(id)', ['id' => Plain::class]), UserRepository::class, 'find'));
        self::assertTrue($this->static($this->pc('args(id)', ['id' => 'int']), UserRepository::class, 'find'));
    }

    #[Test]
    public function isRuntimeOnlyWhenArgumentsAreConstrained(): void
    {
        self::assertTrue($this->pc('args(int, ..)')->isRuntime());
        self::assertTrue($this->pc('args(id, ..)', ['id'])->isRuntime());
        self::assertFalse($this->pc('args(*, ..)')->isRuntime());
        self::assertFalse($this->pc('args(..)')->isRuntime());
    }

    #[Test]
    public function runtimeBuiltinTypes(): void
    {
        self::assertTrue($this->argsMatch('args(int)', [1]));
        self::assertFalse($this->argsMatch('args(int)', ['1']));
        self::assertTrue($this->argsMatch('args(string)', ['a']));
        self::assertTrue($this->argsMatch('args(float)', [1.5]));
        self::assertFalse($this->argsMatch('args(float)', [1]));
        self::assertTrue($this->argsMatch('args(bool)', [true]));
        self::assertTrue($this->argsMatch('args(array)', [[1]]));
        self::assertTrue($this->argsMatch('args(null)', [null]));
        self::assertFalse($this->argsMatch('args(int)', [null]));
    }

    #[Test]
    public function runtimeClassAndInterfaceUseInstanceof(): void
    {
        self::assertTrue($this->argsMatch('args(AOP\Tests\Fixtures\Hierarchy\UserRepository)', [new UserRepository()]));
        self::assertTrue($this->argsMatch('args(AOP\Tests\Fixtures\Hierarchy\AbstractRepository)', [new UserRepository()]));
        self::assertTrue($this->argsMatch('args('.RepositoryInterface::class.')', [new UserRepository()]));
        self::assertFalse($this->argsMatch('args('.Plain::class.')', [new UserRepository()]));
        self::assertFalse($this->argsMatch('args('.Plain::class.')', ['x']));
        self::assertTrue($this->argsMatch('args(AOP\Tests\Fixtures\Hierarchy\*)', [new Plain()]));
    }

    #[Test]
    public function nullablePatternAcceptsNull(): void
    {
        self::assertTrue($this->argsMatch('args(?'.Plain::class.')', [null]));
        self::assertTrue($this->argsMatch('args(?'.Plain::class.')', [new Plain()]));
        self::assertFalse($this->argsMatch('args('.Plain::class.')', [null]));
        self::assertTrue($this->argsMatch('args(?int)', [null]));
        self::assertTrue($this->argsMatch('args(?int)', [3]));
        self::assertFalse($this->argsMatch('args(?int)', ['x']));
    }

    #[Test]
    public function wildcardAcceptsAnythingButNeedsTheArgument(): void
    {
        self::assertTrue($this->argsMatch('args(*)', [null]));
        self::assertTrue($this->argsMatch('args(*, string)', [new Plain(), 'x']));
        self::assertFalse($this->argsMatch('args(*, string)', [new Plain()]));
    }

    #[Test]
    public function runtimeEllipsisAllowsAnyTail(): void
    {
        self::assertTrue($this->argsMatch('args(int, ..)', [1]));
        self::assertTrue($this->argsMatch('args(int, ..)', [1, 'a', null]));
        self::assertFalse($this->argsMatch('args(int, ..)', []));
        self::assertFalse($this->argsMatch('args(int, ..)', ['a', 1]));
        self::assertTrue($this->argsMatch('args(..)', []));
    }

    #[Test]
    public function exactListRequiresExactArgumentCount(): void
    {
        self::assertFalse($this->argsMatch('args(int)', [1, 2]));
        self::assertFalse($this->argsMatch('args(int, int)', [1]));
    }

    #[Test]
    public function variadicArgumentsAreCheckedPositionally(): void
    {
        self::assertTrue($this->argsMatch('args(int, string, string)', [1, 'a', 'b'], 'twoStrings'));
        self::assertFalse($this->argsMatch('args(int, string, string)', [1, 'a', 2], 'twoStrings'));
    }

    #[Test]
    public function bindsArgumentsByPosition(): void
    {
        $pc = $this->pc('args(id, ..)', ['id']);

        self::assertSame(['id' => 7], $this->runtime($pc, [7, 'x'])->bound());
    }

    #[Test]
    public function bindsSeveralArguments(): void
    {
        $pc = $this->pc('args(a, int, b)', ['a', 'b']);
        $ctx = Jp::runtime(new UserRepository(), 'intThenAny', ['x', 2, null]);

        self::assertTrue($pc->matchesRuntime($ctx));
        self::assertSame(['a' => 'x', 'b' => null], $ctx->bound());
    }

    #[Test]
    public function bindingTypeRestrictionFiltersAtRuntime(): void
    {
        $pc = $this->pc('args(id)', ['id' => 'int']);
        $ctx = Jp::runtime(new UserRepository(), 'find', ['nope']);
        self::assertFalse($pc->matchesRuntime($ctx));

        $ok = $this->runtime($pc, [5]);
        self::assertSame(['id' => 5], $ok->bound());

        $class = $this->pc('args(p)', ['p' => Plain::class]);
        $ctx = Jp::runtime(new UserRepository(), 'find', [new UserRepository()]);
        self::assertFalse($class->matchesRuntime($ctx));
    }

    #[Test]
    public function nullableRestrictionAcceptsNull(): void
    {
        $pc = $this->pc('args(p)', ['p' => '?'.Plain::class]);
        $ctx = Jp::runtime(new UserRepository(), 'find', [null]);

        self::assertTrue($pc->matchesRuntime($ctx));
        self::assertSame(['p' => null], $ctx->bound());
    }

    #[Test]
    public function exposesBindingSpecs(): void
    {
        $bindings = $this->pc('args(id, string, name)', ['id' => 'int', 'name'])->bindings();

        self::assertSame(['id', 'name'], array_keys($bindings));
        self::assertSame(BindingKind::ARGS, $bindings['id']->kind);
        self::assertSame('int', $bindings['id']->typeRestriction);
        self::assertNull($bindings['name']->typeRestriction);
    }

    #[Test]
    public function nonBindingIdentifierIsATypePattern(): void
    {
        self::assertSame([], $this->pc('args(string)', ['id'])->bindings());
    }

    #[Test]
    public function fieldSetTakesTheValueAsItsOnlyArgument(): void
    {
        $set = new StaticMatchContext(JoinPointKind::FIELD_SET, Reflect::cls(KindShapes::class), null, new \ReflectionProperty(KindShapes::class, 'count'));
        $get = new StaticMatchContext(JoinPointKind::FIELD_GET, Reflect::cls(KindShapes::class), null, new \ReflectionProperty(KindShapes::class, 'count'));

        self::assertTrue($this->pc('args(int)')->matchesMethod(null, $set));
        self::assertFalse($this->pc('args(string)')->matchesMethod(null, $set), 'the property is an int');
        self::assertTrue($this->pc('args(*)')->matchesMethod(null, $set));
        self::assertFalse($this->pc('args(*, *)')->matchesMethod(null, $set));
        self::assertTrue($this->pc('args(..)')->matchesMethod(null, $set));
        self::assertFalse($this->pc('args(int)')->matchesMethod(null, $get), 'a read has no arguments');
        self::assertTrue($this->pc('args()')->matchesMethod(null, $get));
    }

    #[Test]
    public function exceptionHandlersTakeTheExceptionAsTheirOnlyArgument(): void
    {
        $handler = new StaticMatchContext(JoinPointKind::EXCEPTION_HANDLER, null, null, null, Reflect::cls(\RuntimeException::class));

        self::assertTrue($this->pc('args(Throwable+)')->matchesMethod(null, $handler));
        self::assertFalse($this->pc('args(*, *)')->matchesMethod(null, $handler));
        self::assertFalse($this->pc('args()')->matchesMethod(null, $handler));
    }

    #[Test]
    public function matchesRuntimeAgainstTheValueOfAFieldWrite(): void
    {
        $static = new StaticMatchContext(JoinPointKind::FIELD_SET, Reflect::cls(KindShapes::class), null, new \ReflectionProperty(KindShapes::class, 'count'));
        $joinPoint = new MethodInvocation(
            new JoinPointData(JoinPointKind::FIELD_SET, new KindShapes(), new KindShapes(), new FieldSignature(KindShapes::class, 'count'), new StaticPart(JoinPointKind::FIELD_SET, new FieldSignature(KindShapes::class, 'count'))),
            [7],
            [],
            static fn (array $args): null => null,
        );
        $ctx = new RuntimeMatchContext($joinPoint, $static);

        self::assertTrue($this->pc('args(int)')->matchesRuntime($ctx));
        self::assertFalse($this->pc('args(string)')->matchesRuntime($ctx));
    }

    #[Test]
    public function isNotCallerSensitive(): void
    {
        self::assertFalse($this->pc('args(int)')->isCallerSensitive());
        self::assertFalse((new PointcutExpressionCompiler())->compile('@args(Foo)')->isCallerSensitive());
    }
}
