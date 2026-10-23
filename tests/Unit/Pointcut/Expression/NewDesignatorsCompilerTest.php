<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Pointcut\Expression;

use AOP\Exception\BindingException;
use AOP\Exception\PointcutSyntaxException;
use AOP\Exception\UnsupportedPointcutDesignatorException;
use AOP\Pointcut\AdviceExecutionPointcut;
use AOP\Pointcut\AndPointcut;
use AOP\Pointcut\AtThisPointcut;
use AOP\Pointcut\AtWithincodePointcut;
use AOP\Pointcut\BindingKind;
use AOP\Pointcut\CallPointcut;
use AOP\Pointcut\CflowPointcut;
use AOP\Pointcut\Expression\NamedPointcutResolverInterface;
use AOP\Pointcut\Expression\PointcutExpressionCompiler;
use AOP\Pointcut\GetPointcut;
use AOP\Pointcut\HandlerPointcut;
use AOP\Pointcut\IfPointcut;
use AOP\Pointcut\InitializationPointcut;
use AOP\Pointcut\NotPointcut;
use AOP\Pointcut\OrPointcut;
use AOP\Pointcut\PointcutInterface;
use AOP\Pointcut\SetPointcut;
use AOP\Pointcut\WithincodePointcut;
use AOP\Tests\Fixtures\Hierarchy\Marker;
use AOP\Tests\Fixtures\Pointcut\Conditions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NewDesignatorsCompilerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, class-string<PointcutInterface>}>
     */
    public static function classes(): iterable
    {
        yield 'call' => ['call(* *(..))', CallPointcut::class];
        yield 'withincode' => ['withincode(* *(..))', WithincodePointcut::class];
        yield '@withincode' => ['@withincode(Foo)', AtWithincodePointcut::class];
        yield '@this' => ['@this(Foo)', AtThisPointcut::class];
        yield 'cflow' => ['cflow(within(Foo))', CflowPointcut::class];
        yield 'cflowbelow' => ['cflowbelow(within(Foo))', CflowPointcut::class];
        yield 'if' => ['if('.Conditions::class.'::always())', IfPointcut::class];
        yield 'initialization' => ['initialization(Foo::__construct(..))', InitializationPointcut::class];
        yield 'initialization shorthand' => ['initialization(Foo+)', InitializationPointcut::class];
        yield 'adviceexecution' => ['adviceexecution()', AdviceExecutionPointcut::class];
        yield 'handler' => ['handler(Foo)', HandlerPointcut::class];
        yield 'get' => ['get(* Foo::$x)', GetPointcut::class];
        yield 'set' => ['set(* Foo::$x)', SetPointcut::class];
        yield 'and' => ['call(* *(..)) && handler(Foo)', AndPointcut::class];
        yield 'or' => ['get(* Foo::$x) || set(* Foo::$x)', OrPointcut::class];
        yield 'not' => ['!cflow(within(Foo))', NotPointcut::class];
    }

    /**
     * @param class-string<PointcutInterface> $class
     */
    #[Test]
    #[DataProvider('classes')]
    public function compilesToTheDesignatorsPointcut(string $expression, string $class): void
    {
        self::assertInstanceOf($class, (new PointcutExpressionCompiler())->compile($expression));
    }

    #[Test]
    public function cflowKeepsItsInnerPointcutAndDirection(): void
    {
        $compiler = new PointcutExpressionCompiler();
        $cflow = $compiler->compile('cflow(within(Foo))');
        $below = $compiler->compile('cflowbelow(call(* *(..)))');

        self::assertInstanceOf(CflowPointcut::class, $cflow);
        self::assertInstanceOf(CflowPointcut::class, $below);
        self::assertFalse($cflow->isBelow());
        self::assertTrue($below->isBelow());
        self::assertInstanceOf(CallPointcut::class, $below->inner());
    }

    #[Test]
    public function bindingNamesAndTypesReachThePointcuts(): void
    {
        $compiler = new PointcutExpressionCompiler();
        $pointcut = $compiler->compile('@this(m) && @withincode(t) && cflow(this(o)) && set(* Foo::$x) && args(v)', ['m' => Marker::class, 't' => '?Foo', 'o', 'v' => 'int']);
        $bindings = $pointcut->bindings();

        self::assertSame(['m', 't', 'o', 'v'], array_keys($bindings));
        self::assertSame(BindingKind::AT_THIS, $bindings['m']->kind);
        self::assertSame(Marker::class, $bindings['m']->typeRestriction);
        self::assertSame(BindingKind::AT_WITHINCODE, $bindings['t']->kind);
        self::assertSame('?Foo', $bindings['t']->typeRestriction);
        self::assertSame(BindingKind::THIS, $bindings['o']->kind);
        self::assertSame(BindingKind::ARGS, $bindings['v']->kind);
    }

    #[Test]
    public function aNameThatIsNotAnAdviceParameterStaysATypePattern(): void
    {
        $pointcut = (new PointcutExpressionCompiler())->compile('@this(Marker) && @withincode(Tag)');

        self::assertSame([], $pointcut->bindings());
        self::assertFalse($pointcut->isRuntime());
    }

    #[Test]
    public function theShortFormOfIfResolvesAgainstTheDeclaringAspect(): void
    {
        $pointcut = (new PointcutExpressionCompiler())->compile('if(always())', [], Conditions::class);

        self::assertInstanceOf(IfPointcut::class, $pointcut);
        self::assertSame(Conditions::class, $pointcut->className);
        self::assertSame('always', $pointcut->methodName);
    }

    #[Test]
    public function theShortFormOfIfInANamedPointcutResolvesAgainstThePointcutsAspect(): void
    {
        $resolver = new class implements NamedPointcutResolverInterface {
            /** @var list<array{?string, string, string}> */
            public array $calls = [];

            public function resolve(?string $className, string $methodName, string $fromAspect): string
            {
                $this->calls[] = [$className, $methodName, $fromAspect];

                return 'call(* *(..)) && if(never())';
            }
        };
        $pointcut = (new PointcutExpressionCompiler())->compile(Conditions::class.'::viaPointcut() && execution(* *(..))', [], 'Some\Other\Aspect', $resolver);

        self::assertInstanceOf(AndPointcut::class, $pointcut);
        self::assertSame([[Conditions::class, 'viaPointcut', 'Some\Other\Aspect']], $resolver->calls);
        self::assertTrue($pointcut->isRuntime(), 'if(never()) was resolved against Conditions');
    }

    #[Test]
    public function theShortFormOfIfWithoutAnAspectFails(): void
    {
        $this->expectException(PointcutSyntaxException::class);

        (new PointcutExpressionCompiler())->compile('if(always())');
    }

    #[Test]
    public function negatedBindingsFailAtCompileTime(): void
    {
        $this->expectException(BindingException::class);

        (new PointcutExpressionCompiler())->compile('!(@this(m))', ['m']);
    }

    #[Test]
    public function orBranchesWithDifferentBindingsFail(): void
    {
        $this->expectException(BindingException::class);

        (new PointcutExpressionCompiler())->compile('@this(m) || handler(Foo)', ['m']);
    }

    #[Test]
    public function preinitializationAndStaticinitializationAreUnsupported(): void
    {
        foreach (['preinitialization(Foo)', 'staticinitialization(Foo)'] as $expression) {
            try {
                (new PointcutExpressionCompiler())->compile($expression);
                self::fail('Expected UnsupportedPointcutDesignatorException for '.$expression);
            } catch (UnsupportedPointcutDesignatorException $e) {
                self::assertStringContainsString('PHP has no', $e->getMessage());
            }
        }
    }
}
