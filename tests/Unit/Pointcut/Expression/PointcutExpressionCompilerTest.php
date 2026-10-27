<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Pointcut\Expression;

use AOP\Exception\BindingException;
use AOP\Exception\PointcutSyntaxException;
use AOP\Exception\UnsupportedPointcutDesignatorException;
use AOP\JoinPoint\JoinPointKind;
use AOP\Pointcut\AndPointcut;
use AOP\Pointcut\AnnotationPointcut;
use AOP\Pointcut\ArgsPointcut;
use AOP\Pointcut\AtArgsPointcut;
use AOP\Pointcut\AtTargetPointcut;
use AOP\Pointcut\AtWithinPointcut;
use AOP\Pointcut\BindingKind;
use AOP\Pointcut\BindingSpec;
use AOP\Pointcut\ExecutionPointcut;
use AOP\Pointcut\Expression\NamedPointcutResolverInterface;
use AOP\Pointcut\Expression\PointcutExpressionCompiler;
use AOP\Pointcut\NotPointcut;
use AOP\Pointcut\OrPointcut;
use AOP\Pointcut\PointcutInterface;
use AOP\Pointcut\ServicePointcut;
use AOP\Pointcut\StaticMatchContext;
use AOP\Pointcut\TargetPointcut;
use AOP\Pointcut\ThisPointcut;
use AOP\Pointcut\WithinPointcut;
use AOP\Tests\Fixtures\Functions\ConstructedService;
use AOP\Tests\Fixtures\Hierarchy\Audit;
use AOP\Tests\Fixtures\Hierarchy\Plain;
use AOP\Tests\Fixtures\Hierarchy\UserRepository;
use AOP\Tests\Fixtures\Jp;
use AOP\Tests\Fixtures\Pointcut\Reflect;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PointcutExpressionCompilerTest extends TestCase
{
    /**
     * @param array<int|string, string|null> $names
     */
    private function compile(string $expression, array $names = [], string $aspect = '', ?NamedPointcutResolverInterface $resolver = null): PointcutInterface
    {
        return (new PointcutExpressionCompiler())->compile($expression, $names, $aspect, $resolver);
    }

    /**
     * @param array<string, string> $map "Class::method" (class '' for same aspect) => expression
     */
    private function resolver(array $map): NamedPointcutResolverInterface
    {
        return new class($map) implements NamedPointcutResolverInterface {
            /** @var list<array{?string, string, string}> */
            public array $calls = [];

            /** @param array<string, string> $map */
            public function __construct(private array $map)
            {
            }

            public function resolve(?string $className, string $methodName, string $fromAspect): string
            {
                $this->calls[] = [$className, $methodName, $fromAspect];

                return $this->map[($className ?? $fromAspect).'::'.$methodName]
                    ?? throw new PointcutSyntaxException('unknown '.$methodName);
            }
        };
    }

    /**
     * @return iterable<string, array{string, class-string<PointcutInterface>}>
     */
    public static function typeCases(): iterable
    {
        yield 'execution' => ['execution(* App\Foo::bar(..))', ExecutionPointcut::class];
        yield 'within' => ['within(App\**)', WithinPointcut::class];
        yield 'this' => ['this(App\Foo)', ThisPointcut::class];
        yield 'target' => ['target(App\Foo)', TargetPointcut::class];
        yield 'annotation' => ['@annotation(App\Attr)', AnnotationPointcut::class];
        yield 'at within' => ['@within(App\Attr)', AtWithinPointcut::class];
        yield 'at target' => ['@target(App\Attr)', AtTargetPointcut::class];
        yield 'service' => ['service(app.*)', ServicePointcut::class];
        yield 'args' => ['args(int, ..)', ArgsPointcut::class];
        yield 'at args' => ['@args(App\Attr)', AtArgsPointcut::class];
        yield 'and' => ['within(A) && within(B)', AndPointcut::class];
        yield 'or' => ['within(A) || within(B)', OrPointcut::class];
        yield 'not' => ['!within(A)', NotPointcut::class];
        yield 'parens' => ['(within(A))', WithinPointcut::class];
    }

    /**
     * @param class-string<PointcutInterface> $class
     */
    #[Test]
    #[DataProvider('typeCases')]
    public function buildsExpectedPointcutType(string $expression, string $class): void
    {
        self::assertInstanceOf($class, $this->compile($expression));
    }

    #[Test]
    public function compiledExecutionPointcutMatches(): void
    {
        $p = $this->compile('execution(public ?object '.UserRepository::class.'::find(int))');
        $class = Reflect::cls(UserRepository::class);
        $ctx = Jp::ctx(UserRepository::class, 'find');

        self::assertTrue($p->matchesMethod($class->getMethod('find'), $ctx));
        self::assertFalse($p->matchesMethod($class->getMethod('noParams'), $ctx));
    }

    #[Test]
    public function precedenceNotAndOr(): void
    {
        $p = $this->compile('!within('.Plain::class.') && within('.UserRepository::class.') || within('.Plain::class.')');

        self::assertInstanceOf(OrPointcut::class, $p);
        $plain = Reflect::cls(Plain::class);
        self::assertTrue($p->matchesMethod($plain->getMethod('find'), Jp::ctx($plain->getName())));
    }

    #[Test]
    public function bindingIdentifierRecognisedOnlyWhenInAdviceParameterNames(): void
    {
        $bound = $this->compile('target(repo)', ['repo']);
        $notBound = $this->compile('target(repo)', ['other']);
        $none = $this->compile('target(repo)');

        self::assertTrue($bound->isRuntime());
        self::assertEquals(['repo' => new BindingSpec('repo', BindingKind::TARGET, null)], $bound->bindings());
        self::assertFalse($notBound->isRuntime());
        self::assertSame([], $notBound->bindings());
        self::assertFalse($none->isRuntime());
    }

    #[Test]
    public function unboundIdentifierIsExactFqcnTypePattern(): void
    {
        $p = $this->compile('within(Plain)');
        $plain = Reflect::cls(Plain::class);

        self::assertFalse($p->matchesMethod($plain->getMethod('find'), Jp::ctx($plain->getName())));
        self::assertTrue($this->compile('within('.Plain::class.')')->matchesMethod($plain->getMethod('find'), Jp::ctx($plain->getName())));
    }

    #[Test]
    public function bindingNamesAreCaseSensitive(): void
    {
        self::assertSame([], $this->compile('this(Proxy)', ['proxy'])->bindings());
        self::assertArrayHasKey('proxy', $this->compile('this(proxy)', ['proxy'])->bindings());
    }

    /**
     * @return iterable<string, array{string, BindingKind}>
     */
    public static function bindingKinds(): iterable
    {
        yield 'this' => ['this(x)', BindingKind::THIS];
        yield 'target' => ['target(x)', BindingKind::TARGET];
        yield 'annotation' => ['@annotation(x)', BindingKind::ANNOTATION];
        yield 'within' => ['@within(x)', BindingKind::WITHIN];
        yield 'at target' => ['@target(x)', BindingKind::AT_TARGET];
    }

    #[Test]
    #[DataProvider('bindingKinds')]
    public function bindingKindPerDesignator(string $expression, BindingKind $kind): void
    {
        $bindings = $this->compile($expression, ['x'])->bindings();

        self::assertSame($kind, $bindings['x']->kind);
    }

    #[Test]
    public function adviceParameterTypesCanBePassedAsMapForTypeRestriction(): void
    {
        $p = $this->compile('@annotation(audit)', ['audit' => Audit::class]);

        self::assertSame(Audit::class, $p->bindings()['audit']->typeRestriction);
        $class = Reflect::cls(UserRepository::class);
        self::assertTrue($p->matchesMethod($class->getMethod('audited'), Jp::ctx($class->getName())));
        self::assertFalse($p->matchesMethod($class->getMethod('find'), Jp::ctx($class->getName())));
    }

    #[Test]
    public function argsAndAtArgsAreRuntimeStubs(): void
    {
        $args = $this->compile('args(id, ..)', ['id']);
        $atArgs = $this->compile('@args(a)', ['a']);

        self::assertInstanceOf(ArgsPointcut::class, $args);
        self::assertTrue($args->isRuntime());
        self::assertInstanceOf(AtArgsPointcut::class, $atArgs);
        self::assertTrue($atArgs->isRuntime());
    }

    #[Test]
    public function bindingsMergeAcrossCombinators(): void
    {
        $p = $this->compile('this(a) && target(b)', ['a', 'b']);

        self::assertSame(['a', 'b'], array_keys($p->bindings()));
    }

    #[Test]
    public function orBranchesBindingDifferentNamesAreRejected(): void
    {
        $this->expectException(BindingException::class);
        $this->expectExceptionMessage('id');

        $this->compile('execution(* '.UserRepository::class.'::find(..)) || args(id)', ['id' => 'int']);
    }

    #[Test]
    public function orBranchesBindingTheSameNamesAreAccepted(): void
    {
        $p = $this->compile('(args(id) && within(A)) || (args(id, ..) && within(B))', ['id' => 'int']);

        self::assertSame(['id'], array_keys($p->bindings()));
    }

    #[Test]
    public function bindingInsideNotIsRejected(): void
    {
        $this->expectException(BindingException::class);

        $this->compile('!target(b)', ['b']);
    }

    #[Test]
    public function resolvesSameAspectReference(): void
    {
        $resolver = $this->resolver(['App\Aspect::services' => 'within('.Plain::class.')']);
        $p = $this->compile('services()', [], 'App\Aspect', $resolver);
        $plain = Reflect::cls(Plain::class);

        self::assertInstanceOf(WithinPointcut::class, $p);
        self::assertTrue($p->matchesMethod($plain->getMethod('find'), Jp::ctx($plain->getName())));
        self::assertSame([[null, 'services', 'App\Aspect']], $resolver->calls); // @phpstan-ignore-line
    }

    #[Test]
    public function resolvesCrossClassReferenceAndNestsReferences(): void
    {
        $resolver = $this->resolver([
            'App\Common::all' => 'App\Common::inner() || service(x)',
            'App\Common::inner' => 'within(A)',
        ]);

        $p = $this->compile('\App\Common::all() && within(B)', [], 'App\Aspect', $resolver);

        self::assertInstanceOf(AndPointcut::class, $p);
        self::assertSame(
            [['App\Common', 'all', 'App\Aspect'], ['App\Common', 'inner', 'App\Common']],
            $resolver->calls, // @phpstan-ignore-line
        );
    }

    #[Test]
    public function sameAspectReferenceInsideResolvedExpressionUsesThatAspect(): void
    {
        $resolver = $this->resolver([
            'App\Common::outer' => 'inner()',
            'App\Common::inner' => 'within(A)',
        ]);

        $this->compile('App\Common::outer()', [], 'App\Aspect', $resolver);

        self::assertSame(['App\Common', 'inner'], [$resolver->calls[1][2], $resolver->calls[1][1]]); // @phpstan-ignore-line
    }

    #[Test]
    public function referencedPointcutBindingsUseTheAdviceParameterNames(): void
    {
        $resolver = $this->resolver(['A::p' => 'target(x)']);

        self::assertArrayHasKey('x', $this->compile('p()', ['x'], 'A', $resolver)->bindings());
    }

    #[Test]
    public function sameReferenceTwiceIsNotACycle(): void
    {
        $resolver = $this->resolver(['A::p' => 'within(X)']);

        self::assertInstanceOf(AndPointcut::class, $this->compile('p() && p()', [], 'A', $resolver));
    }

    #[Test]
    public function directCycleThrows(): void
    {
        $resolver = $this->resolver(['A::a' => 'a()']);

        $this->expectException(PointcutSyntaxException::class);
        $this->expectExceptionMessageMatches('/cyclic/i');

        $this->compile('a()', [], 'A', $resolver);
    }

    #[Test]
    public function aCycleIsReportedAsTheWholeChainOfReferences(): void
    {
        $resolver = $this->resolver(['A::a' => 'b()', 'A::b' => 'a()']);

        $this->expectException(PointcutSyntaxException::class);
        $this->expectExceptionMessage('Cyclic named pointcut reference: A::a -> A::b -> A::a.');

        $this->compile('a()', [], 'A', $resolver);
    }

    #[Test]
    public function indirectCycleThrows(): void
    {
        $resolver = $this->resolver(['A::a' => 'b()', 'A::b' => 'B::c() || within(X)', 'B::c' => 'A::a()']);

        $this->expectException(PointcutSyntaxException::class);
        $this->expectExceptionMessageMatches('/A::a/');

        $this->compile('a()', [], 'A', $resolver);
    }

    #[Test]
    public function referenceWithoutResolverThrows(): void
    {
        $this->expectException(PointcutSyntaxException::class);

        $this->compile('foo()');
    }

    #[Test]
    public function syntaxErrorsAndUnsupportedDesignatorsPropagate(): void
    {
        try {
            $this->compile('preinitialization(Foo)');
            self::fail('expected exception');
        } catch (UnsupportedPointcutDesignatorException $e) {
            self::assertStringContainsString('preinitialization', $e->getMessage());
        }

        $this->expectException(PointcutSyntaxException::class);
        $this->compile('within(');
    }

    #[Test]
    public function runtimeContextCompilesBindingEndToEnd(): void
    {
        $p = $this->compile('target(t) && within('.UserRepository::class.')', ['t']);
        $target = new UserRepository();
        $ctx = Jp::runtime($target, 'find');

        self::assertTrue($p->matchesRuntime($ctx));
        self::assertSame($target, $ctx->bound()['t']);
    }

    #[Test]
    public function compilesFunctionExecutionPatterns(): void
    {
        require_once __DIR__.'/../../../Fixtures/Functions/functions.php';
        $fmt = new \ReflectionFunction('AOP\Tests\Fixtures\Functions\fmt');
        $internal = new \ReflectionFunction('str_replace');
        $fmtCtx = new StaticMatchContext(JoinPointKind::FUNCTION_EXECUTION, null, $fmt);
        $internalCtx = new StaticMatchContext(JoinPointKind::FUNCTION_EXECUTION, null, $internal);

        $namespaced = $this->compile('execution(* AOP\Tests\Fixtures\Functions\fmt*(..))');
        self::assertTrue($namespaced->matchesClass(null, $fmtCtx) && $namespaced->matchesMethod($fmt, $fmtCtx));
        self::assertFalse($namespaced->matchesMethod($internal, $internalCtx));

        $prefix = $this->compile('execution(* str_*(..))');
        self::assertTrue($prefix->matchesMethod($internal, $internalCtx));
        self::assertFalse($prefix->matchesMethod($fmt, $fmtCtx));

        $typed = $this->compile('execution(string AOP\Tests\Fixtures\Functions\fmt(string))');
        self::assertTrue($typed->matchesMethod($fmt, $fmtCtx));
        self::assertFalse($this->compile('execution(int AOP\Tests\Fixtures\Functions\fmt(string))')->matchesMethod($fmt, $fmtCtx));

        $all = $this->compile('execution(* *(..))');
        self::assertTrue($all->matchesMethod($fmt, $fmtCtx));
        self::assertTrue($all->matchesMethod($internal, $internalCtx));
    }

    #[Test]
    public function compilesConstructorExecutionPatterns(): void
    {
        $constructor = new \ReflectionMethod(ConstructedService::class, '__construct');
        $ctx = Jp::ctx(ConstructedService::class, '__construct', JoinPointKind::CONSTRUCTOR_EXECUTION);
        $class = Reflect::cls(ConstructedService::class);

        foreach (['execution(* '.ConstructedService::class.'::__construct(..))', 'execution(public * '.ConstructedService::class.'::__construct(string))', 'execution(* '.ConstructedService::class.'::*(..))'] as $expression) {
            $pointcut = $this->compile($expression);
            self::assertTrue($pointcut->matchesClass($class, $ctx) && $pointcut->matchesMethod($constructor, $ctx), $expression);
        }
        self::assertFalse($this->compile('execution(* '.ConstructedService::class.'::build(..))')->matchesMethod($constructor, $ctx));
        self::assertFalse($this->compile('execution(* '.UserRepository::class.'::__construct(..))')->matchesClass($class, $ctx));
    }

    #[Test]
    public function callerSensitivityOfCompiledExpressions(): void
    {
        self::assertFalse($this->compile('execution(* *(..))')->isCallerSensitive());
        self::assertFalse($this->compile('target(Foo) && args(..)')->isCallerSensitive());
        self::assertTrue($this->compile('within(App\\*)')->isCallerSensitive());
        self::assertTrue($this->compile('execution(* *(..)) && within(App\\*)')->isCallerSensitive());
        self::assertTrue($this->compile('execution(* *(..)) || this(App\\Foo)')->isCallerSensitive());
        self::assertTrue($this->compile('!@within(App\\Foo)')->isCallerSensitive());
        self::assertFalse($this->compile('!execution(* *(..)) && service(a.*)')->isCallerSensitive());
    }

    #[Test]
    public function orBranchesBindingDifferentNamesFailAtCompileTimeNamingTheExpressionAndTheNames(): void
    {
        $expression = 'args(a) || execution(* App\X::m(..))';

        try {
            $this->compile($expression, ['a']);
            self::fail('Expected BindingException');
        } catch (BindingException $e) {
            self::assertStringContainsString($expression, $e->getMessage());
            self::assertStringContainsString('[a]', $e->getMessage());
            self::assertStringContainsString('[]', $e->getMessage());
            self::assertStringContainsString('"||"', $e->getMessage());
            self::assertSame(0, $e->getCode());
            self::assertNotNull($e->getPrevious());
        }
    }

    #[Test]
    public function orBranchesBindingDifferentNamesFailThroughNamedPointcutsAndNesting(): void
    {
        $resolver = $this->resolver(['A::byArgs' => 'args(a)', 'A::plain' => 'within(X)']);

        foreach (['A::byArgs() || A::plain()', '(args(a) && within(X)) || (args(b) && within(Y))', 'within(X) || (within(Y) || args(a))'] as $expression) {
            try {
                $this->compile($expression, ['a', 'b'], 'A', $resolver);
                self::fail('Expected BindingException for '.$expression);
            } catch (BindingException $e) {
                self::assertStringContainsString($expression, $e->getMessage());
            }
        }
    }

    #[Test]
    public function orBranchesBindingTheSameNamesCompile(): void
    {
        self::assertSame(['a'], array_keys($this->compile('args(a) || (args(a, ..) && within(X))', ['a'])->bindings()));
    }
}
