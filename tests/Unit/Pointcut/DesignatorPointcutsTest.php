<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Pointcut;

use AOP\Pointcut\AnnotationPointcut;
use AOP\Pointcut\AtTargetPointcut;
use AOP\Pointcut\AtWithinPointcut;
use AOP\Pointcut\ServicePointcut;
use AOP\Pointcut\BindingKind;
use AOP\Pointcut\BindingSpec;
use AOP\Pointcut\Expression\PointcutExpressionCompiler;
use AOP\Pointcut\Pattern\NamePattern;
use AOP\Pointcut\Pattern\TypePattern;
use AOP\Pointcut\PointcutInterface;
use AOP\Pointcut\RuntimeMatchContext;
use AOP\Pointcut\StaticMatchContext;
use AOP\Pointcut\TargetPointcut;
use AOP\Pointcut\ThisPointcut;
use AOP\Pointcut\WithinPointcut;
use AOP\Proxy\ProxyStrategy;
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
use AOP\Tests\Fixtures\Pointcut\Reflect;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DesignatorPointcutsTest extends TestCase
{
    /**
     * @param class-string $class
     */
    private function ctx(string $class, ?string $service = null, ProxyStrategy $strategy = ProxyStrategy::SUBCLASS): StaticMatchContext
    {
        return new StaticMatchContext(Reflect::cls($class), $service, $strategy);
    }

    /**
     * @param class-string $class
     */
    private function static(PointcutInterface $pointcut, string $class, string $method = 'find', ?string $service = null, ProxyStrategy $strategy = ProxyStrategy::SUBCLASS): bool
    {
        $ctx = $this->ctx($class, $service, $strategy);

        return $pointcut->matchesClass($ctx->targetClass, $ctx) && $pointcut->matchesMethod($ctx->targetClass->getMethod($method), $ctx);
    }

    /**
     * @param class-string $targetClass
     */
    private function runtime(string $targetClass, string $method = 'find'): RuntimeMatchContext
    {
        $target = new $targetClass();

        return new RuntimeMatchContext(new \stdClass(), $target, new \ReflectionMethod($targetClass, $method), []);
    }

    // within

    #[Test]
    public function withinMatchesTargetClassFqcn(): void
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
    public function withinUsesTargetClassNotDeclaringClass(): void
    {
        $pointcut = new WithinPointcut(new TypePattern('AOP\Tests\Fixtures\Hierarchy\AbstractRepository'));

        self::assertFalse($this->static($pointcut, UserRepository::class, 'save'));
    }

    // this / target

    #[Test]
    public function thisWithSubclassStrategyUsesTargetHierarchy(): void
    {
        $pointcut = new ThisPointcut(new TypePattern(UserRepository::class, false, true));

        self::assertTrue($this->static($pointcut, ExtraRepository::class, strategy: ProxyStrategy::SUBCLASS));
        self::assertFalse($this->static($pointcut, Plain::class, strategy: ProxyStrategy::SUBCLASS));
    }

    #[Test]
    public function thisWithInterfaceStrategyConsidersInterfacesOnly(): void
    {
        $iface = new ThisPointcut(new TypePattern(ReadableInterface::class, false, true));
        $cls = new ThisPointcut(new TypePattern(UserRepository::class, false, true));

        self::assertTrue($this->static($iface, UserRepository::class, strategy: ProxyStrategy::INTERFACE));
        self::assertFalse($this->static($cls, UserRepository::class, strategy: ProxyStrategy::INTERFACE));
        self::assertFalse($this->static($iface, Plain::class, strategy: ProxyStrategy::INTERFACE));
    }

    #[Test]
    public function targetMatchesTargetHierarchy(): void
    {
        $pointcut = new TargetPointcut(new TypePattern(ReadableInterface::class, false, true));

        self::assertTrue($this->static($pointcut, UserRepository::class, strategy: ProxyStrategy::INTERFACE));
        self::assertFalse($this->static($pointcut, Plain::class));
        self::assertFalse((new TargetPointcut(new TypePattern(UserRepository::class)))->isRuntime());
    }

    #[Test]
    public function thisBindingBindsProxy(): void
    {
        $pointcut = new ThisPointcut(new BindingSpec('proxy', BindingKind::THIS, null));
        $ctx = $this->runtime(UserRepository::class);

        self::assertTrue($pointcut->isRuntime());
        self::assertTrue($this->static($pointcut, Plain::class));
        self::assertEquals(['proxy' => new BindingSpec('proxy', BindingKind::THIS, null)], $pointcut->bindings());
        self::assertTrue($pointcut->matchesRuntime($ctx));
        self::assertSame($ctx->proxy, $ctx->bound()['proxy']);
    }

    #[Test]
    public function targetBindingBindsTarget(): void
    {
        $pointcut = new TargetPointcut(new BindingSpec('t', BindingKind::TARGET, null));
        $ctx = $this->runtime(UserRepository::class);

        self::assertTrue($pointcut->isRuntime());
        self::assertTrue($pointcut->matchesRuntime($ctx));
        self::assertSame($ctx->target, $ctx->bound()['t']);
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
    public function atWithinMatchesTargetClassAttribute(): void
    {
        $pointcut = new AtWithinPointcut(new TypePattern(Marker::class));

        self::assertTrue($this->static($pointcut, UserRepository::class));
        self::assertFalse($this->static($pointcut, Plain::class));
    }

    #[Test]
    public function atWithinAlsoChecksDeclaringClass(): void
    {
        $pointcut = new AtWithinPointcut(new TypePattern(Marker::class));

        // ExtraRepository carries no attribute itself, but `find` is declared in the marked UserRepository.
        self::assertTrue($this->static($pointcut, ExtraRepository::class, 'find'));
        self::assertFalse($this->static($pointcut, ExtraRepository::class, 'save'));
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
        self::assertFalse($pointcut->isRuntime());
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
        foreach (ProxyStrategy::cases() as $strategy) {
            $ctx = new StaticMatchContext($target, null, $strategy, [Auditable::class]);
            $plain = new StaticMatchContext($target, null, $strategy);
            $type = new TypePattern(Auditable::class);

            self::assertTrue((new ThisPointcut($type))->matchesClass($target, $ctx), $strategy->value);
            self::assertTrue((new ThisPointcut($type))->matchesMethod($method, $ctx), $strategy->value);
            self::assertFalse((new ThisPointcut($type))->matchesMethod($method, $plain), $strategy->value);
            self::assertTrue((new ThisPointcut(new TypePattern('AOP\Tests\Fixtures\Introduction\Audit*')))->matchesMethod($method, $ctx), $strategy->value);
            self::assertFalse((new TargetPointcut($type))->matchesMethod($method, $ctx), $strategy->value);
            self::assertFalse((new WithinPointcut(new TypePattern(Auditable::class, false, true)))->matchesMethod($method, $ctx), $strategy->value);
        }
    }

    #[Test]
    public function thisMatchesParentsOfIntroducedInterfaces(): void
    {
        $target = Reflect::cls(WorkTarget::class);
        foreach (ProxyStrategy::cases() as $strategy) {
            $ctx = new StaticMatchContext($target, null, $strategy, [Tracked::class]);

            self::assertTrue((new ThisPointcut(new TypePattern(Trackable::class)))->matchesMethod($target->getMethod('work'), $ctx), $strategy->value);
        }
    }
}
