<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Pointcut;

use AOP\JoinPoint\JoinPointKind;
use AOP\Pointcut\ExecutionPointcut;
use AOP\Pointcut\Expression\Ast\ParamListNode;
use AOP\Pointcut\Expression\Ast\TypePatternNode;
use AOP\Pointcut\Pattern\ModifierPattern;
use AOP\Pointcut\Pattern\NamePattern;
use AOP\Pointcut\Pattern\ParameterPattern;
use AOP\Pointcut\Pattern\TypePattern;
use AOP\Pointcut\StaticMatchContext;
use AOP\Tests\Fixtures\Functions\ConstructedService;
use AOP\Tests\Fixtures\Hierarchy\Plain;
use AOP\Tests\Fixtures\Hierarchy\ReadableInterface;
use AOP\Tests\Fixtures\Hierarchy\RepositoryInterface;
use AOP\Tests\Fixtures\Hierarchy\Sub\ExtraRepository;
use AOP\Tests\Fixtures\Hierarchy\UserRepository;
use AOP\Tests\Fixtures\Introduction\Auditable;
use AOP\Tests\Fixtures\Introduction\ClashingPrivateTarget;
use AOP\Tests\Fixtures\Introduction\WorkTarget;
use AOP\Tests\Fixtures\Jp;
use AOP\Tests\Fixtures\Pointcut\Reflect;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ExecutionPointcutTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__.'/../../Fixtures/Functions/functions.php';
    }

    /**
     * @param 'public'|'protected'|null $modifier
     */
    private function pointcut(
        ?string $modifier = null,
        ?TypePattern $return = null,
        ?TypePattern $declaring = null,
        string $name = '*',
        ?ParamListNode $params = null,
    ): ExecutionPointcut {
        return new ExecutionPointcut(
            new ModifierPattern($modifier),
            $return,
            $declaring,
            new NamePattern($name),
            ParameterPattern::fromNode($params ?? new ParamListNode([], true)),
        );
    }

    /**
     * @param class-string $class
     */
    private function executes(ExecutionPointcut $pointcut, string $class, string $method): bool
    {
        $reflection = Reflect::cls($class);
        $ctx = Jp::ctx($class, $method);

        return $pointcut->matchesClass($reflection, $ctx) && $pointcut->matchesMethod($reflection->getMethod($method), $ctx);
    }

    /**
     * @return iterable<string, array{'public'|'protected'|null, class-string, string, bool}>
     */
    public static function modifierCases(): iterable
    {
        yield 'any/public' => [null, UserRepository::class, 'find', true];
        yield 'any/protected' => [null, UserRepository::class, 'internal', true];
        yield 'public/public' => ['public', UserRepository::class, 'find', true];
        yield 'public/protected' => ['public', UserRepository::class, 'internal', false];
        yield 'protected/protected' => ['protected', UserRepository::class, 'internal', true];
        yield 'protected/public' => ['protected', UserRepository::class, 'find', false];
    }

    /**
     * @param 'public'|'protected'|null $modifier
     * @param class-string              $class
     */
    #[Test]
    #[DataProvider('modifierCases')]
    public function filtersByModifier(?string $modifier, string $class, string $method, bool $expected): void
    {
        self::assertSame($expected, $this->executes($this->pointcut($modifier), $class, $method));
    }

    /**
     * @return iterable<string, array{TypePattern, string, bool}>
     */
    public static function returnCases(): iterable
    {
        yield 'exact class-less builtin' => [new TypePattern('string'), 'noParams', true];
        yield 'builtin mismatch' => [new TypePattern('int'), 'noParams', false];
        yield 'void' => [new TypePattern('void'), 'intThenAny', true];
        yield 'nullable needs ?' => [new TypePattern('object'), 'find', false];
        yield 'nullable with ?' => [new TypePattern('object', true), 'find', true];
        yield '? pattern on non-nullable' => [new TypePattern('string', true), 'noParams', false];
        yield 'static literal' => [new TypePattern('static'), 'selfReturn', true];
        yield 'self literal' => [new TypePattern('self'), 'repo', true];
        yield 'self resolved to the declaring class' => [new TypePattern(UserRepository::class), 'repo', true];
        yield 'self does not resolve to another class' => [new TypePattern(RepositoryInterface::class), 'repo', false];
        yield 'self vs static' => [new TypePattern('self'), 'selfReturn', false];
        yield 'untyped return never matches a pattern' => [new TypePattern('mixed'), 'untypedReturn', false];
        yield 'star glob' => [new TypePattern('*'), 'noParams', true];
    }

    #[Test]
    #[DataProvider('returnCases')]
    public function filtersByReturnType(TypePattern $return, string $method, bool $expected): void
    {
        self::assertSame($expected, $this->executes($this->pointcut(return: $return), UserRepository::class, $method));
    }

    #[Test]
    public function nullReturnPatternMatchesEverythingIncludingUntyped(): void
    {
        self::assertTrue($this->executes($this->pointcut(), UserRepository::class, 'untypedReturn'));
        self::assertTrue($this->executes($this->pointcut(), UserRepository::class, 'find'));
    }

    #[Test]
    public function returnClassTypeSupportsSubtypes(): void
    {
        $exact = $this->pointcut(return: new TypePattern(RepositoryInterface::class), name: 'asAbstract');
        $plus = $this->pointcut(return: new TypePattern(RepositoryInterface::class, false, true), name: 'asAbstract');
        $other = $this->pointcut(return: new TypePattern(UserRepository::class, false, true), name: 'asAbstract');

        self::assertFalse($this->executes($exact, UserRepository::class, 'asAbstract'));
        self::assertTrue($this->executes($plus, UserRepository::class, 'asAbstract'));
        self::assertFalse($this->executes($other, UserRepository::class, 'asAbstract'));
    }

    #[Test]
    public function matchesNameGlob(): void
    {
        $pointcut = $this->pointcut(name: 'find*');

        self::assertTrue($this->executes($pointcut, UserRepository::class, 'find'));
        self::assertFalse($this->executes($pointcut, UserRepository::class, 'rename'));
    }

    #[Test]
    public function matchesParameters(): void
    {
        $pointcut = $this->pointcut(params: new ParamListNode([]));

        self::assertTrue($this->executes($pointcut, UserRepository::class, 'noParams'));
        self::assertFalse($this->executes($pointcut, UserRepository::class, 'find'));
    }

    #[Test]
    public function declaringTypeMatchesTargetClass(): void
    {
        $pointcut = $this->pointcut(declaring: new TypePattern(UserRepository::class), name: 'find');

        self::assertTrue($this->executes($pointcut, UserRepository::class, 'find'));
        self::assertFalse($this->executes($pointcut, Plain::class, 'find'));
    }

    #[Test]
    public function declaringTypeMatchesInterfaceThatDeclaresTheMethod(): void
    {
        $pointcut = $this->pointcut(declaring: new TypePattern(ReadableInterface::class), name: 'find');

        self::assertTrue($this->executes($pointcut, UserRepository::class, 'find'));
        self::assertTrue($this->executes($pointcut, ExtraRepository::class, 'find'));
        self::assertFalse($this->executes($pointcut, Plain::class, 'find'), 'Plain does not implement the interface');
    }

    #[Test]
    public function declaringTypeMatchesAncestorThatDeclaresTheMethod(): void
    {
        $abstract = $this->pointcut(declaring: new TypePattern('AOP\Tests\Fixtures\Hierarchy\AbstractRepository'), name: 'save');
        $notDeclaredThere = $this->pointcut(declaring: new TypePattern('AOP\Tests\Fixtures\Hierarchy\AbstractRepository'), name: 'find');

        self::assertTrue($this->executes($abstract, UserRepository::class, 'save'));
        self::assertFalse($this->executes($notDeclaredThere, UserRepository::class, 'find'));
    }

    #[Test]
    public function declaringTypeWithPlusMatchesSubclasses(): void
    {
        $pointcut = $this->pointcut(declaring: new TypePattern(UserRepository::class, false, true), name: 'extra');

        self::assertTrue($this->executes($pointcut, ExtraRepository::class, 'extra'));
    }

    #[Test]
    public function matchesClassPrunesUnrelatedHierarchies(): void
    {
        $pointcut = $this->pointcut(declaring: new TypePattern(ReadableInterface::class));
        $plain = Reflect::cls(Plain::class);

        self::assertFalse($pointcut->matchesClass($plain, Jp::ctx(Plain::class)));
    }

    #[Test]
    public function isStaticWithoutBindings(): void
    {
        $pointcut = $this->pointcut();

        self::assertFalse($pointcut->isRuntime());
        self::assertSame([], $pointcut->bindings());
    }

    #[Test]
    public function introducedMethodsBelongToTheIntroducedInterfaceOnly(): void
    {
        $target = Reflect::cls(WorkTarget::class);
        $ctx = Jp::ctx($target->getName(), introduced: [Auditable::class]);
        $introduced = new \ReflectionMethod(Auditable::class, 'recordAudit');
        $ofInterface = $this->pointcut(declaring: new TypePattern(Auditable::class));
        $ofTarget = $this->pointcut(declaring: new TypePattern(WorkTarget::class));

        self::assertTrue($ofInterface->matchesClass($target, $ctx) && $ofInterface->matchesMethod($introduced, $ctx));
        self::assertFalse($ofInterface->matchesMethod($target->getMethod('work'), $ctx), 'methods of the target are not declared by the introduced interface');
        self::assertFalse($ofInterface->matchesClass($target, Jp::ctx($target->getName())), 'without the introduction the target has no such ancestor');
        self::assertFalse($ofTarget->matchesMethod($introduced, $ctx), 'the target type does not declare introduced methods');
        self::assertTrue($this->pointcut()->matchesMethod($introduced, $ctx));
    }

    #[Test]
    public function aNonPublicTargetMethodDoesNotHideTheIntroducedOneOfTheSameName(): void
    {
        $target = Reflect::cls(ClashingPrivateTarget::class);
        $ctx = Jp::ctx($target->getName(), introduced: [Auditable::class]);

        self::assertTrue($this->pointcut(declaring: new TypePattern(Auditable::class))->matchesMethod(new \ReflectionMethod(Auditable::class, 'recordAudit'), $ctx));
    }

    #[Test]
    public function matchesFunctionsByNamespacedName(): void
    {
        $ctx = new StaticMatchContext(JoinPointKind::FUNCTION_EXECUTION, null, new \ReflectionFunction('AOP\Tests\Fixtures\Functions\fmt'));
        $function = $ctx->function;

        self::assertTrue($this->pointcut(name: 'AOP\Tests\Fixtures\Functions\fmt*')->matchesMethod($function, $ctx));
        self::assertTrue($this->pointcut(name: '*')->matchesMethod($function, $ctx));
        self::assertFalse($this->pointcut(name: 'fmt*')->matchesMethod($function, $ctx), 'the name is namespaced');
        self::assertFalse($this->pointcut(name: 'AOP\Tests\Other\*')->matchesMethod($function, $ctx));
        self::assertTrue($this->pointcut()->matchesClass(null, $ctx));
    }

    #[Test]
    public function matchesInternalFunctions(): void
    {
        $function = new \ReflectionFunction('str_replace');
        $ctx = new StaticMatchContext(JoinPointKind::FUNCTION_EXECUTION, null, $function);

        self::assertTrue($this->pointcut(name: 'str_*')->matchesMethod($function, $ctx));
        self::assertFalse($this->pointcut(name: 'array_*')->matchesMethod($function, $ctx));
    }

    #[Test]
    public function functionsCheckParametersAndReturnType(): void
    {
        $function = new \ReflectionFunction('AOP\Tests\Fixtures\Functions\fmt');
        $ctx = new StaticMatchContext(JoinPointKind::FUNCTION_EXECUTION, null, $function);

        self::assertTrue($this->pointcut(return: new TypePattern('string'), params: new ParamListNode([new TypePatternNode('string')]))->matchesMethod($function, $ctx));
        self::assertFalse($this->pointcut(return: new TypePattern('int'))->matchesMethod($function, $ctx));
        self::assertFalse($this->pointcut(params: new ParamListNode([]))->matchesMethod($function, $ctx));
    }

    #[Test]
    public function functionsHaveNoModifierAndNoDeclaringType(): void
    {
        $function = new \ReflectionFunction('AOP\Tests\Fixtures\Functions\fmt');
        $ctx = new StaticMatchContext(JoinPointKind::FUNCTION_EXECUTION, null, $function);

        self::assertFalse($this->pointcut(modifier: 'public')->matchesMethod($function, $ctx));
        self::assertFalse($this->pointcut(declaring: new TypePattern('*'))->matchesMethod($function, $ctx));
        self::assertFalse($this->pointcut(declaring: new TypePattern('*'))->matchesClass(null, $ctx));
    }

    #[Test]
    public function aMethodDoesNotMatchAsFunctionExecutionNorTheReverse(): void
    {
        $method = new \ReflectionMethod(UserRepository::class, 'find');
        $asFunction = Jp::ctx(UserRepository::class, 'find', JoinPointKind::FUNCTION_EXECUTION);
        $function = new \ReflectionFunction('str_replace');
        $asMethod = new StaticMatchContext(JoinPointKind::METHOD_EXECUTION, null, $function);

        self::assertFalse($this->pointcut()->matchesMethod($method, $asFunction));
        self::assertFalse($this->pointcut()->matchesMethod($function, $asMethod));
    }

    #[Test]
    public function matchesConstructorsAsMagicMethodName(): void
    {
        $class = Reflect::cls(ConstructedService::class);
        $constructor = $class->getConstructor();
        self::assertNotNull($constructor);
        $ctx = Jp::ctx(ConstructedService::class, '__construct', JoinPointKind::CONSTRUCTOR_EXECUTION);

        $byName = $this->pointcut(declaring: new TypePattern(ConstructedService::class), name: '__construct');
        self::assertTrue($byName->matchesClass($class, $ctx) && $byName->matchesMethod($constructor, $ctx));
        self::assertTrue($this->pointcut(return: null, name: '__construct')->matchesMethod($constructor, $ctx), 'the return type pattern * matches constructors');
        self::assertFalse($this->pointcut(return: new TypePattern('void'), name: '__construct')->matchesMethod($constructor, $ctx), 'constructors declare no return type');
        self::assertFalse($this->pointcut(name: 'build')->matchesMethod($constructor, $ctx));
    }

    #[Test]
    public function onlyAppliesToExecutionKinds(): void
    {
        $method = new \ReflectionMethod(UserRepository::class, 'find');
        $pointcut = $this->pointcut();

        foreach ([JoinPointKind::METHOD_CALL, JoinPointKind::FIELD_GET, JoinPointKind::FIELD_SET, JoinPointKind::EXCEPTION_HANDLER, JoinPointKind::INITIALIZATION, JoinPointKind::CONSTRUCTOR_CALL, JoinPointKind::FUNCTION_CALL] as $kind) {
            $ctx = Jp::ctx(UserRepository::class, 'find', $kind);
            self::assertFalse($pointcut->matchesClass(Reflect::cls(UserRepository::class), $ctx), $kind->value);
            self::assertFalse($pointcut->matchesMethod($method, $ctx), $kind->value);
        }
        self::assertFalse($pointcut->matchesMethod(null, Jp::ctx(UserRepository::class, null, JoinPointKind::FIELD_GET)));
    }

    #[Test]
    public function isNotCallerSensitive(): void
    {
        self::assertFalse($this->pointcut()->isCallerSensitive());
    }
}
