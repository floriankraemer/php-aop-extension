<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Pointcut;

use AOP\Advisor\Advisor;
use AOP\Attribute\Configurable;
use AOP\Configurable\ConfigurableAspect;
use AOP\Configurable\ConfigurablePointcut;
use AOP\Exception\AspectDefinitionException;
use AOP\JoinPoint\JoinPointKind;
use AOP\Pointcut\AnnotationPointcut;
use AOP\Pointcut\Api\TruePointcut as ApiTruePointcut;
use AOP\Pointcut\FieldInterception;
use AOP\Pointcut\Pattern\TypePattern;
use AOP\Pointcut\StaticMatchContext;
use AOP\Pointcut\TruePointcut;
use AOP\Tests\Fixtures\Api\ConfiguredService;
use AOP\Tests\Fixtures\Api\ConstructorLessConfigured;
use AOP\Tests\Fixtures\Runtime\Trace;
use AOP\Tests\Support\FakeEngine;
use AOP\Weaver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class OnlyOnProperty
{
}

#[\Attribute(\Attribute::TARGET_METHOD)]
final class OnlyOnMethod
{
}

#[\Attribute]
final class OnAnything
{
}

/**
 * Which pointcuts make Weaver::enable() demand field interception, and the messages of that guard.
 */
final class FieldInterceptionRequirementTest extends TestCase
{
    #[Test]
    public function annotationOnAPropertyOnlyAttributeIsAFieldPointcut(): void
    {
        self::assertTrue(FieldInterception::requiredBy(new AnnotationPointcut(new TypePattern(OnlyOnProperty::class))));
    }

    #[Test]
    public function annotationOnAnAttributeThatCanSitOnMethodsIsNot(): void
    {
        self::assertFalse(FieldInterception::requiredBy(new AnnotationPointcut(new TypePattern(OnlyOnMethod::class))));
        self::assertFalse(FieldInterception::requiredBy(new AnnotationPointcut(new TypePattern(OnAnything::class))));
    }

    #[Test]
    public function annotationOnWildcardUnknownOrNonAttributeClassesIsNot(): void
    {
        self::assertFalse(FieldInterception::requiredBy(new AnnotationPointcut(new TypePattern('AOP\Tests\Unit\Pointcut\On*'))));
        self::assertFalse(FieldInterception::requiredBy(new AnnotationPointcut(new TypePattern(OnlyOnProperty::class, false, true))));
        self::assertFalse(FieldInterception::requiredBy(new AnnotationPointcut(new TypePattern('No\Such\Attribute'))));
        self::assertFalse(FieldInterception::requiredBy(new AnnotationPointcut(new TypePattern(self::class))));
    }

    #[Test]
    public function catchAllPointcutsAreAcceptedWithoutFieldInterception(): void
    {
        foreach ([new TruePointcut(), ApiTruePointcut::INSTANCE] as $pointcut) {
            $engine = new FakeEngine();
            $engine->fieldInterception = false;
            $weaver = new Weaver($engine);
            $weaver->addAdvisor(new Advisor('catch.all', null, 1, $pointcut, new Trace('t')));

            $weaver->enable();

            self::assertTrue($weaver->isEnabled());
        }
    }

    #[Test]
    public function configurableWeaverEnablesWithoutFieldInterception(): void
    {
        $engine = new FakeEngine();
        $engine->fieldInterception = false;
        $weaver = new Weaver($engine);
        $weaver->configurable(static function (object $o, Configurable $a): void {
        });

        $weaver->enable();

        self::assertTrue($weaver->isEnabled());
    }

    #[Test]
    public function configurablePointcutMatchesConstructorExecutionOfClassesWithConstructor(): void
    {
        $class = new \ReflectionClass(ConfiguredService::class);
        $pointcut = new ConfigurablePointcut(static fn (): bool => false);

        self::assertTrue($pointcut->matchesClass($class, new StaticMatchContext(JoinPointKind::CONSTRUCTOR_EXECUTION, $class)));
        self::assertFalse($pointcut->matchesClass($class, new StaticMatchContext(JoinPointKind::METHOD_EXECUTION, $class)));
    }

    #[Test]
    public function configurablePointcutFailsLoudlyForAConstructorLessClassWithoutFieldInterception(): void
    {
        $class = new \ReflectionClass(ConstructorLessConfigured::class);
        $pointcut = new ConfigurablePointcut(static fn (): bool => false);

        $this->expectException(AspectDefinitionException::class);
        $this->expectExceptionMessage('Class "'.ConstructorLessConfigured::class.'" is #[Configurable] and has no constructor');
        $pointcut->matchesClass($class, new StaticMatchContext(JoinPointKind::METHOD_EXECUTION, $class));
    }

    #[Test]
    public function configurablePointcutAcceptsAConstructorLessClassWithFieldInterception(): void
    {
        $class = new \ReflectionClass(ConstructorLessConfigured::class);
        $available = new ConfigurablePointcut(static fn (): bool => true);

        self::assertFalse($available->matchesClass($class, new StaticMatchContext(JoinPointKind::METHOD_EXECUTION, $class)));
        self::assertTrue($available->matchesClass($class, new StaticMatchContext(JoinPointKind::INITIALIZATION, $class)));
        self::assertTrue((new ConfigurableAspect(static function (): void {
        }))->toAdvisor()->pointcut->matchesClass($class, new StaticMatchContext(JoinPointKind::INITIALIZATION, $class)));
    }

    #[Test]
    public function missingExtensionOrDisabledInterceptionKeepsTheEngineError(): void
    {
        $engine = new FakeEngine();
        $engine->active = false;
        $engine->fieldInterception = false;
        $weaver = new Weaver($engine);
        $weaver->addAdvisor((new \AOP\Advisor\Api\DefaultPointcutAdvisor('get(* App\Foo::$bar)', new Trace('t'), id: 'field'))->toAdvisor());

        // The guard stays out of the way: the engine's register() reports the missing extension or aop.enable=0.
        $weaver->enable();

        self::assertTrue($weaver->isEnabled());
    }

    #[Test]
    public function wovenBackendMessageNamesTheHookSetting(): void
    {
        $engine = new FakeEngine();
        $engine->fieldInterception = false;
        $weaver = new Weaver($engine);
        $weaver->addAdvisor((new \AOP\Advisor\Api\DefaultPointcutAdvisor('get(* App\Foo::$bar)', new Trace('t'), id: 'field'))->toAdvisor());

        $this->expectException(AspectDefinitionException::class);
        $this->expectExceptionMessage('(woven)');
        $this->expectExceptionMessage('aop.interception=hook');
        $weaver->enable();
    }
}
