<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Pointcut;

use AOP\JoinPoint\JoinPointKind;
use AOP\Pointcut\AnnotationPointcut;
use AOP\Pointcut\AtTargetPointcut;
use AOP\Pointcut\AtWithinPointcut;
use AOP\Pointcut\BindingKind;
use AOP\Pointcut\BindingSpec;
use AOP\Pointcut\EnclosingContext;
use AOP\Pointcut\Expression\PointcutExpressionCompiler;
use AOP\Pointcut\Pattern\NamePattern;
use AOP\Pointcut\Pattern\TypePattern;
use AOP\Pointcut\PointcutInterface;
use AOP\Pointcut\RuntimeMatchContext;
use AOP\Pointcut\ServicePointcut;
use AOP\Pointcut\StaticMatchContext;
use AOP\Pointcut\TargetPointcut;
use AOP\Pointcut\ThisPointcut;
use AOP\Pointcut\WithinPointcut;
use AOP\Tests\Fixtures\Hierarchy\Audit;
use AOP\Tests\Fixtures\Hierarchy\Marker;
use AOP\Tests\Fixtures\Hierarchy\Plain;
use AOP\Tests\Fixtures\Hierarchy\ReadableInterface;
use AOP\Tests\Fixtures\Hierarchy\SpecialAudit;
use AOP\Tests\Fixtures\Hierarchy\Sub\ExtraRepository;
use AOP\Tests\Fixtures\Hierarchy\UserRepository;
use AOP\Tests\Fixtures\Introduction\Auditable;
use AOP\Tests\Fixtures\Introduction\Trackable;
use AOP\Tests\Fixtures\Introduction\Tracked;
use AOP\Tests\Fixtures\Introduction\WorkTarget;
use AOP\Tests\Fixtures\Jp;
use AOP\Tests\Fixtures\Pointcut\Reflect;
use AOP\Tests\Fixtures\Targets\KindShapes;
use AOP\Tests\Fixtures\Targets\KindShapesChild;
use AOP\Tests\Fixtures\Targets\PropertyTag;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DesignatorPointcutsTest extends TestCase
{
    /**
     * @param class-string $class
     */
    private function static(
        PointcutInterface $pointcut,
        string $class,
        string $method = 'find',
        ?string $service = null,
        JoinPointKind $kind = JoinPointKind::METHOD_EXECUTION,
        ?EnclosingContext $caller = null,
    ): bool {
        $ctx = Jp::ctx($class, $method, $kind, $service, caller: $caller);

        return $pointcut->matchesClass($ctx->targetClass, $ctx) && $pointcut->matchesMethod($ctx->function, $ctx);
    }

    /**
     * @param class-string $class
     */
    private function onField(PointcutInterface $pointcut, string $class, string $property, JoinPointKind $kind, ?EnclosingContext $caller = null): bool
    {
        $ctx = new StaticMatchContext($kind, new \ReflectionClass($class), null, new \ReflectionProperty($class, $property), caller: $caller);

        return $pointcut->matchesClass($ctx->targetClass, $ctx) && $pointcut->matchesMethod(null, $ctx);
    }

    /**
     * @param class-string $targetClass
     */
    private function runtime(string $targetClass, string $method = 'find'): RuntimeMatchContext
    {
        return Jp::runtime(new $targetClass(), $method);
    }

    // within

    #[Test]
    public function withinMatchesTheClassOfTheExecutingCode(): void
    {
        $pointcut = new WithinPointcut(new TypePattern('AOP\Tests\Fixtures\Hierarchy\**'));

        self::assertTrue($this->static($pointcut, UserRepository::class));
        self::assertTrue($this->static($pointcut, ExtraRepository::class));
        self::assertFalse($this->static(new WithinPointcut(new TypePattern(Plain::class)), UserRepository::class));
        self::assertFalse($pointcut->isRuntime());
        self::assertSame([], $pointcut->bindings());
    }

    #[Test]
    public function withinPlusMatchesSubtypes(): void
    {
        $pointcut = new WithinPointcut(new TypePattern(ReadableInterface::class, false, true));

        self::assertTrue($this->static($pointcut, ExtraRepository::class));
        self::assertFalse($this->static($pointcut, Plain::class));
    }

    #[Test]
    public function withinUsesTheDeclaringClassOfAnInheritedMethod(): void
    {
        $pointcut = new WithinPointcut(new TypePattern('AOP\Tests\Fixtures\Hierarchy\AbstractRepository'));

        self::assertTrue($this->static($pointcut, UserRepository::class, 'save'), 'save() is declared in AbstractRepository');
        self::assertFalse($this->static($pointcut, UserRepository::class, 'find'), 'find() is declared in UserRepository');
    }

    #[Test]
    public function withinFunctionExecutionIsFalse(): void
    {
        $ctx = new StaticMatchContext(JoinPointKind::FUNCTION_EXECUTION, null, new \ReflectionFunction('str_replace'));
        $pointcut = new WithinPointcut(new TypePattern('**'));

        self::assertFalse($pointcut->matchesClass(null, $ctx));
        self::assertFalse($pointcut->matchesMethod($ctx->function, $ctx));
    }

    #[Test]
    public function withinLooksAtTheCallerForCallKindsFieldsAndHandlers(): void
    {
        $inRepo = new WithinPointcut(new TypePattern(UserRepository::class));
        $caller = Jp::caller(UserRepository::class, 'find');
        $other = Jp::caller(Plain::class, 'find');

        foreach ([JoinPointKind::METHOD_CALL, JoinPointKind::CONSTRUCTOR_CALL, JoinPointKind::FUNCTION_CALL] as $kind) {
            self::assertTrue($this->static($inRepo, KindShapes::class, 'inc', kind: $kind, caller: $caller), $kind->value);
            self::assertFalse($this->static($inRepo, KindShapes::class, 'inc', kind: $kind, caller: $other), $kind->value);
            self::assertFalse($this->static($inRepo, KindShapes::class, 'inc', kind: $kind), $kind->value.' without a known caller');
        }
        self::assertTrue($this->onField($inRepo, KindShapes::class, 'count', JoinPointKind::FIELD_GET, $caller));
        self::assertTrue($this->onField($inRepo, KindShapes::class, 'count', JoinPointKind::FIELD_SET, $caller));
        self::assertFalse($this->onField($inRepo, KindShapes::class, 'count', JoinPointKind::FIELD_SET, $other));
    }

    #[Test]
    public function withinUsesTheDeclaringClassOfTheCallingMethod(): void
    {
        $inAbstract = new WithinPointcut(new TypePattern('AOP\Tests\Fixtures\Hierarchy\AbstractRepository'));
        // runtime class of the caller is UserRepository, the calling method is declared in AbstractRepository
        $caller = new EnclosingContext(Reflect::cls(UserRepository::class), new \ReflectionMethod(UserRepository::class, 'save'));

        self::assertTrue($this->static($inAbstract, KindShapes::class, 'inc', kind: JoinPointKind::METHOD_CALL, caller: $caller));
    }

    #[Test]
    public function withinIsCallerSensitive(): void
    {
        self::assertTrue((new WithinPointcut(new TypePattern('*')))->isCallerSensitive());
    }

    // this / target

    #[Test]
    public function thisUsesTheHierarchyOfTheExecutingClass(): void
    {
        $pointcut = new ThisPointcut(new TypePattern(UserRepository::class, false, true));

        self::assertTrue($this->static($pointcut, ExtraRepository::class));
        self::assertFalse($this->static($pointcut, Plain::class));
        self::assertTrue($this->static(new ThisPointcut(new TypePattern(ReadableInterface::class, false, true)), UserRepository::class));
    }

    #[Test]
    public function thisNeverMatchesStaticMethodsAndFunctions(): void
    {
        $pointcut = new ThisPointcut(new TypePattern('**'));
        $function = new StaticMatchContext(JoinPointKind::FUNCTION_EXECUTION, null, new \ReflectionFunction('str_replace'));

        self::assertFalse($this->static($pointcut, KindShapes::class, 'make'));
        self::assertTrue($this->static($pointcut, KindShapes::class, 'inc'));
        self::assertFalse($pointcut->matchesMethod($function->function, $function));
        self::assertFalse($pointcut->matchesClass(null, $function));
    }

    #[Test]
    public function thisIsTheCallersObjectForCallKinds(): void
    {
        $pointcut = new ThisPointcut(new TypePattern(UserRepository::class, false, true));

        self::assertTrue($this->static($pointcut, KindShapes::class, 'inc', kind: JoinPointKind::METHOD_CALL, caller: Jp::caller(ExtraRepository::class, 'extra')));
        self::assertFalse($this->static($pointcut, KindShapes::class, 'inc', kind: JoinPointKind::METHOD_CALL, caller: Jp::caller(Plain::class, 'find')));
        self::assertFalse($this->static($pointcut, KindShapes::class, 'inc', kind: JoinPointKind::METHOD_CALL), 'unknown caller');
        self::assertFalse(
            $this->static($pointcut, KindShapes::class, 'inc', kind: JoinPointKind::METHOD_CALL, caller: new EnclosingContext(Reflect::cls(UserRepository::class), new \ReflectionFunction('str_replace'))),
            'a function has no this',
        );
        self::assertFalse(
            $this->static($pointcut, KindShapes::class, 'inc', kind: JoinPointKind::METHOD_CALL, caller: new EnclosingContext(Reflect::cls(KindShapes::class), new \ReflectionMethod(KindShapes::class, 'make'))),
            'a static caller has no this',
        );
    }

    #[Test]
    public function thisOnFieldsAndHandlersIsTheAccessingObject(): void
    {
        $pointcut = new ThisPointcut(new TypePattern(UserRepository::class));

        self::assertTrue($this->onField($pointcut, KindShapes::class, 'count', JoinPointKind::FIELD_GET, Jp::caller(UserRepository::class, 'find')));
        self::assertFalse($this->onField($pointcut, KindShapes::class, 'count', JoinPointKind::FIELD_GET));
    }

    #[Test]
    public function thisIsCallerSensitive(): void
    {
        self::assertTrue((new ThisPointcut(new TypePattern('*')))->isCallerSensitive());
    }

    #[Test]
    public function targetMatchesTargetHierarchy(): void
    {
        $pointcut = new TargetPointcut(new TypePattern(ReadableInterface::class, false, true));

        self::assertTrue($this->static($pointcut, UserRepository::class));
        self::assertFalse($this->static($pointcut, Plain::class));
        self::assertFalse((new TargetPointcut(new TypePattern(UserRepository::class)))->isRuntime());
    }

    #[Test]
    public function targetIsTheCalleeForCallKindsAndNeverForStaticMembersFunctionsAndHandlers(): void
    {
        $pointcut = new TargetPointcut(new TypePattern(KindShapes::class, false, true));

        self::assertTrue($this->static($pointcut, KindShapesChild::class, 'inc', kind: JoinPointKind::METHOD_CALL));
        self::assertFalse($this->static($pointcut, KindShapes::class, 'make'));
        self::assertFalse($this->static($pointcut, KindShapes::class, 'make', kind: JoinPointKind::METHOD_CALL));
        self::assertTrue($this->onField($pointcut, KindShapes::class, 'count', JoinPointKind::FIELD_GET));
        self::assertFalse($this->onField($pointcut, KindShapes::class, 'total', JoinPointKind::FIELD_GET));
        self::assertFalse($pointcut->matchesMethod(null, new StaticMatchContext(JoinPointKind::EXCEPTION_HANDLER, Reflect::cls(KindShapes::class))));
        self::assertFalse($pointcut->matchesMethod(null, new StaticMatchContext(JoinPointKind::FUNCTION_EXECUTION, null)));
        self::assertFalse($pointcut->isCallerSensitive());
    }

    #[Test]
    public function thisBindingBindsTheExecutingObject(): void
    {
        $pointcut = new ThisPointcut(new BindingSpec('self', BindingKind::THIS, null));
        $ctx = $this->runtime(UserRepository::class);

        self::assertTrue($pointcut->isRuntime());
        self::assertTrue($this->static($pointcut, UserRepository::class));
        self::assertEquals(['self' => new BindingSpec('self', BindingKind::THIS, null)], $pointcut->bindings());
        self::assertTrue($pointcut->matchesRuntime($ctx));
        self::assertSame($ctx->this(), $ctx->bound()['self']);
    }

    #[Test]
    public function thisRuntimeFailsWithoutAnExecutingObject(): void
    {
        $pointcut = new ThisPointcut(new BindingSpec('self', BindingKind::THIS, null));
        $ctx = Jp::runtime(new KindShapes(), 'make');

        self::assertNull($ctx->this());
        self::assertFalse($pointcut->matchesRuntime($ctx));
        self::assertSame([], $ctx->bound());
    }

    #[Test]
    public function targetBindingBindsTarget(): void
    {
        $pointcut = new TargetPointcut(new BindingSpec('t', BindingKind::TARGET, null));
        $ctx = $this->runtime(UserRepository::class);

        self::assertTrue($pointcut->isRuntime());
        self::assertTrue($pointcut->matchesRuntime($ctx));
        self::assertSame($ctx->target(), $ctx->bound()['t']);
    }

    #[Test]
    public function targetRuntimeFailsWithoutATarget(): void
    {
        $pointcut = new TargetPointcut(new BindingSpec('t', BindingKind::TARGET, null));

        self::assertFalse($pointcut->matchesRuntime(Jp::runtime(new KindShapes(), 'make')));
    }

    #[Test]
    public function bindingWithTypeRestrictionAlsoFiltersStatically(): void
    {
        $pointcut = new TargetPointcut(new BindingSpec('t', BindingKind::TARGET, ReadableInterface::class));

        self::assertTrue($this->static($pointcut, UserRepository::class));
        self::assertFalse($this->static($pointcut, Plain::class));
    }

    // @annotation

    #[Test]
    public function annotationMatchesMethodAttribute(): void
    {
        $pointcut = new AnnotationPointcut(new TypePattern(Audit::class));

        self::assertTrue($this->static($pointcut, UserRepository::class, 'audited'));
        self::assertFalse($this->static($pointcut, UserRepository::class, 'find'));
        self::assertFalse($pointcut->isRuntime());
    }

    #[Test]
    public function annotationMatchesFunctionsAndFields(): void
    {
        require_once __DIR__.'/../../Fixtures/Functions/functions.php';
        $pointcut = new AnnotationPointcut(new TypePattern(PropertyTag::class));
        $tagged = new StaticMatchContext(JoinPointKind::FUNCTION_EXECUTION, null, new \ReflectionFunction('AOP\Tests\Fixtures\Functions\tagged'));
        $untagged = new StaticMatchContext(JoinPointKind::FUNCTION_EXECUTION, null, new \ReflectionFunction('str_replace'));

        self::assertTrue($pointcut->matchesMethod($tagged->function, $tagged));
        self::assertFalse($pointcut->matchesMethod($untagged->function, $untagged));
        self::assertTrue($this->onField($pointcut, KindShapes::class, 'count', JoinPointKind::FIELD_GET));
        self::assertFalse($this->onField($pointcut, KindShapes::class, 'total', JoinPointKind::FIELD_GET));
        self::assertFalse($pointcut->matchesMethod(null, new StaticMatchContext(JoinPointKind::EXCEPTION_HANDLER, null)));
    }

    #[Test]
    public function annotationBindingBindsFieldAttribute(): void
    {
        $pointcut = new AnnotationPointcut(new BindingSpec('tag', BindingKind::ANNOTATION, PropertyTag::class));
        $static = new StaticMatchContext(JoinPointKind::FIELD_GET, Reflect::cls(KindShapes::class), null, new \ReflectionProperty(KindShapes::class, 'count'));
        $ctx = new RuntimeMatchContext(Jp::invocation(new KindShapes(), 'inc', [], kind: JoinPointKind::FIELD_GET), $static);

        self::assertTrue($pointcut->matchesRuntime($ctx));
        $tag = $ctx->bound()['tag'];
        self::assertInstanceOf(PropertyTag::class, $tag);
        self::assertSame('count', $tag->name);
    }

    #[Test]
    public function annotationPlusMatchesAttributeSubclass(): void
    {
        $exact = new AnnotationPointcut(new TypePattern(Audit::class));
        $plus = new AnnotationPointcut(new TypePattern(Audit::class, false, true));

        self::assertFalse($this->static($exact, UserRepository::class, 'specialAudited'));
        self::assertTrue($this->static($plus, UserRepository::class, 'specialAudited'));
        self::assertTrue($this->static($plus, UserRepository::class, 'audited'));
    }

    #[Test]
    public function annotationBindingBindsAttributeInstance(): void
    {
        $pointcut = new AnnotationPointcut(new BindingSpec('audit', BindingKind::ANNOTATION, Audit::class));
        $ctx = $this->runtime(UserRepository::class, 'audited');

        self::assertTrue($pointcut->isRuntime());
        self::assertTrue($this->static($pointcut, UserRepository::class, 'audited'));
        self::assertFalse($this->static($pointcut, UserRepository::class, 'find'));
        self::assertTrue($pointcut->matchesRuntime($ctx));
        $bound = $ctx->bound()['audit'];
        self::assertInstanceOf(Audit::class, $bound);
        self::assertSame('x', $bound->action);
    }

    #[Test]
    public function annotationBindingWithoutTypeMatchesAnyAttributedMethod(): void
    {
        $pointcut = new AnnotationPointcut(new BindingSpec('a', BindingKind::ANNOTATION, null));
        $ctx = $this->runtime(UserRepository::class, 'specialAudited');

        self::assertTrue($this->static($pointcut, UserRepository::class, 'specialAudited'));
        self::assertTrue($pointcut->matchesRuntime($ctx));
        self::assertInstanceOf(SpecialAudit::class, $ctx->bound()['a']);
    }

    #[Test]
    public function annotationBindingRuntimeFailsWhenAttributeMissing(): void
    {
        $pointcut = new AnnotationPointcut(new BindingSpec('a', BindingKind::ANNOTATION, Audit::class));

        self::assertFalse($pointcut->matchesRuntime($this->runtime(UserRepository::class, 'find')));
    }

    // @within / @target

    #[Test]
    public function atWithinMatchesTheDeclaringClassAttribute(): void
    {
        $pointcut = new AtWithinPointcut(new TypePattern(Marker::class));

        self::assertTrue($this->static($pointcut, UserRepository::class));
        self::assertFalse($this->static($pointcut, Plain::class));
    }

    #[Test]
    public function atWithinChecksTheClassDeclaringTheMethod(): void
    {
        $pointcut = new AtWithinPointcut(new TypePattern(Marker::class));

        // ExtraRepository carries no attribute itself, but `find` is declared in the marked UserRepository.
        self::assertTrue($this->static($pointcut, ExtraRepository::class, 'find'));
        self::assertFalse($this->static($pointcut, ExtraRepository::class, 'save'));
        self::assertFalse($this->static($pointcut, ExtraRepository::class, 'extra'), 'extra() is declared in the unmarked ExtraRepository');
    }

    #[Test]
    public function atWithinLooksAtTheCallerForCallKinds(): void
    {
        $pointcut = new AtWithinPointcut(new TypePattern(Marker::class));

        self::assertTrue($this->static($pointcut, Plain::class, 'find', kind: JoinPointKind::METHOD_CALL, caller: Jp::caller(UserRepository::class, 'find')));
        self::assertFalse($this->static($pointcut, UserRepository::class, 'find', kind: JoinPointKind::METHOD_CALL, caller: Jp::caller(Plain::class, 'find')));
        self::assertFalse($this->static($pointcut, UserRepository::class, 'find', kind: JoinPointKind::METHOD_CALL));
        self::assertTrue($pointcut->isCallerSensitive());
    }

    #[Test]
    public function atWithinBindsAttributeInstance(): void
    {
        $pointcut = new AtWithinPointcut(new BindingSpec('m', BindingKind::WITHIN, Marker::class));
        $ctx = $this->runtime(UserRepository::class);

        self::assertTrue($pointcut->isRuntime());
        self::assertTrue($pointcut->matchesRuntime($ctx));
        $bound = $ctx->bound()['m'];
        self::assertInstanceOf(Marker::class, $bound);
        self::assertSame('user', $bound->label);
    }

    #[Test]
    public function atTargetMatchesRuntimeClassAttributeOnly(): void
    {
        $pointcut = new AtTargetPointcut(new TypePattern(Marker::class));

        self::assertTrue($this->static($pointcut, UserRepository::class));
        self::assertFalse($this->static($pointcut, ExtraRepository::class, 'find'), 'attribute is declared on the parent only');
        self::assertFalse($this->static($pointcut, Plain::class));
        self::assertFalse($this->static($pointcut, KindShapes::class, 'make'), 'a static method has no target object');
        self::assertFalse($pointcut->matchesClass(null, new StaticMatchContext(JoinPointKind::FUNCTION_EXECUTION, null)));
        self::assertFalse($pointcut->isRuntime());
        self::assertFalse($pointcut->isCallerSensitive());
    }

    #[Test]
    public function atTargetBindsAttributeInstance(): void
    {
        $pointcut = new AtTargetPointcut(new BindingSpec('m', BindingKind::AT_TARGET, null));
        $ctx = $this->runtime(UserRepository::class);

        self::assertTrue($pointcut->isRuntime());
        self::assertSame(BindingKind::AT_TARGET, $pointcut->bindings()['m']->kind);
        self::assertTrue($pointcut->matchesRuntime($ctx));
        self::assertInstanceOf(Marker::class, $ctx->bound()['m']);
        self::assertFalse($pointcut->matchesRuntime($this->runtime(Plain::class)));
    }

    // service

    #[Test]
    public function serviceMatchesGlobOnServiceName(): void
    {
        $pointcut = new ServicePointcut(new NamePattern('app.*'));

        self::assertTrue($this->static($pointcut, Plain::class, service: 'app.user_repo'));
        self::assertFalse($this->static($pointcut, Plain::class, service: 'other.user_repo'));
        self::assertFalse($this->static($pointcut, Plain::class, service: null));
        self::assertFalse($pointcut->isRuntime());
        self::assertFalse($pointcut->isCallerSensitive());
        self::assertSame([], $pointcut->bindings());
    }

    #[Test]
    public function serviceMatchesAutowiredFqcnServiceIds(): void
    {
        $compiler = new PointcutExpressionCompiler();
        $exact = $compiler->compile('service(App\Service\StringService)');
        $glob = $compiler->compile('service(App\Service\*)');

        self::assertTrue($this->static($exact, Plain::class, service: 'App\Service\StringService'));
        self::assertFalse($this->static($exact, Plain::class, service: 'App\Service\OtherService'));
        self::assertTrue($this->static($glob, Plain::class, service: 'App\Service\OtherService'));
        self::assertFalse($this->static($glob, Plain::class, service: 'App\Controller\HomeController'));
    }

    #[Test]
    public function thisMatchesIntroducedInterfacesButTargetAndWithinDoNot(): void
    {
        $target = Reflect::cls(WorkTarget::class);
        $method = $target->getMethod('work');
        $ctx = Jp::ctx(WorkTarget::class, 'work', introduced: [Auditable::class]);
        $plain = Jp::ctx(WorkTarget::class, 'work');
        $type = new TypePattern(Auditable::class);

        self::assertTrue((new ThisPointcut($type))->matchesClass($target, $ctx));
        self::assertTrue((new ThisPointcut($type))->matchesMethod($method, $ctx));
        self::assertFalse((new ThisPointcut($type))->matchesMethod($method, $plain));
        self::assertTrue((new ThisPointcut(new TypePattern('AOP\Tests\Fixtures\Introduction\Audit*')))->matchesMethod($method, $ctx));
        self::assertFalse((new TargetPointcut($type))->matchesMethod($method, $ctx));
        self::assertFalse((new WithinPointcut(new TypePattern(Auditable::class, false, true)))->matchesMethod($method, $ctx));
    }

    #[Test]
    public function thisMatchesParentsOfIntroducedInterfaces(): void
    {
        $ctx = Jp::ctx(WorkTarget::class, 'work', introduced: [Tracked::class]);

        self::assertTrue((new ThisPointcut(new TypePattern(Trackable::class)))->matchesMethod($ctx->function, $ctx));
    }
}
