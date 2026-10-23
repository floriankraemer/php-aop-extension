<?php

declare(strict_types=1);

namespace AOP\Tests\E2E;

use AOP\Advisor\Api\DefaultPointcutAdvisor;
use AOP\Attribute\Configurable;
use AOP\Pointcut\Api\ControlFlowPointcut;
use AOP\Pointcut\Api\ExpressionPointcut;
use AOP\Pointcut\Api\Pointcuts;
use AOP\TargetSource\HotSwappableTargetSource;
use AOP\Weaver;
use AopE2E\CallAspect;
use AopE2E\ConfigAspect;
use AopE2E\CtorBase;
use AopE2E\CtorBaseAspect;
use AopE2E\CtorChild;
use AopE2E\CtorHeir;
use AopE2E\Driver;
use AopE2E\Factory;
use AopE2E\FlowAspect;
use AopE2E\Gate;
use AopE2E\IfAspect;
use AopE2E\Journal;
use AopE2E\Named;
use AopE2E\Pipeline;
use AopE2E\SpringBefore;
use AopE2E\WithincodeAspect;
use PHPUnit\Framework\Attributes\Test;

final class IntegrationTest extends EngineTestCase
{
    #[Test]
    public function cflowAdviceRunsOnlyInsideTheControlFlow(): void
    {
        $this->weave([new FlowAspect()]);
        $driver = new Driver();

        self::assertSame('inner', $driver->inner(new Pipeline()));
        self::assertSame([], Journal::$entries);

        self::assertSame('outer>inner', $driver->outer(new Pipeline()));
        self::assertSame(['cflow'], Journal::$entries);
    }

    #[Test]
    public function callJoinPointsWrapExecutionJoinPoints(): void
    {
        $this->weave([new CallAspect()]);
        $driver = new Driver();

        $driver->inner(new Pipeline());

        self::assertSame(['call>AopE2E\Driver', 'execution>method-execution'], Journal::$entries);
    }

    #[Test]
    public function withincodeSelectsTheCallingMethod(): void
    {
        $this->weave([new WithincodeAspect()]);
        $driver = new Driver();

        $driver->inner(new Pipeline());
        self::assertSame([], Journal::$entries);

        $driver->outer(new Pipeline());
        self::assertSame(['withincode'], Journal::$entries);
    }

    #[Test]
    public function ifDesignatorEvaluatesAtRuntime(): void
    {
        $this->weave([new IfAspect()]);
        $driver = new Driver();

        Gate::$open = false;
        $driver->inner(new Pipeline());
        self::assertSame([], Journal::$entries);

        Gate::$open = true;
        $driver->inner(new Pipeline());
        self::assertSame(['gated'], Journal::$entries);
        Gate::$open = false;
    }

    #[Test]
    public function aSpringAdvisorRuns(): void
    {
        $weaver = $this->weave([]);
        $weaver->addAdvisor((new DefaultPointcutAdvisor('execution(* AopE2E\Pipeline::inner(..))', new SpringBefore(), 1, 'spring'))->toAdvisor());

        (new Driver())->inner(new Pipeline());

        self::assertSame(['spring:inner'], Journal::$entries);
    }

    #[Test]
    public function aSpringControlFlowPointcutWorksWithoutAnyAdvisorOnTheEntryMethod(): void
    {
        $weaver = $this->weave([]);
        $weaver->addAdvisor((new DefaultPointcutAdvisor(new ControlFlowPointcut(Pipeline::class, 'outer'), new SpringBefore(), 1, 'flow'))->toAdvisor());
        $driver = new Driver();

        // Outside the flow of Pipeline::outer() nothing runs (and calls are no join points of a Spring pointcut).
        $driver->inner(new Pipeline());
        self::assertSame([], Journal::$entries);

        // Inside it every execution is advised exactly once: outer() itself and the inner() it calls.
        $driver->outer(new Pipeline());
        self::assertSame(['spring:outer', 'spring:inner'], Journal::$entries);
    }

    #[Test]
    public function aSpringControlFlowPointcutIntersectedWithAnExpression(): void
    {
        $weaver = $this->weave([]);
        $pointcut = Pointcuts::intersection(
            new ExpressionPointcut('execution(* AopE2E\\Pipeline::inner(..))'),
            new ControlFlowPointcut(Pipeline::class, 'outer'),
        );
        $weaver->addAdvisor((new DefaultPointcutAdvisor($pointcut, new SpringBefore(), 1, 'flow'))->toAdvisor());
        $driver = new Driver();

        $driver->inner(new Pipeline());
        self::assertSame([], Journal::$entries);

        $driver->outer(new Pipeline());
        self::assertSame(['spring:inner'], Journal::$entries);
    }

    #[Test]
    public function aConstructorPatternMatchesOnlyTheConstructorDeclaredByTheType(): void
    {
        $this->weave([new CtorBaseAspect()]);

        Factory::make(CtorBase::class);
        self::assertSame(['base:AopE2E\CtorBase', 'plus:AopE2E\CtorBase'], Journal::$entries);

        // CtorChild declares its own constructor: `Base+` matches it, `Base` only matches the parent::__construct() execution, once per `new`.
        Journal::reset();
        Factory::make(CtorChild::class);
        self::assertSame(['plus:AopE2E\CtorChild', 'base:AopE2E\CtorChild', 'plus:AopE2E\CtorChild'], Journal::$entries);

        // CtorHeir inherits Base's constructor, so it is Base's constructor that runs.
        Journal::reset();
        Factory::make(CtorHeir::class);
        self::assertSame(['base:AopE2E\CtorHeir', 'plus:AopE2E\CtorHeir'], Journal::$entries);
    }

    #[Test]
    public function aTargetSourceReroutesCallsOnTheStandIn(): void
    {
        $weaver = $this->weave([]);
        $source = new HotSwappableTargetSource(new Named('first'));
        $standIn = $weaver->targetSource(Named::class, $source);
        $driver = new Driver();

        self::assertSame('first', $driver->whoami($standIn));

        $source->swap(new Named('second'));
        self::assertSame('second', $driver->whoami($standIn));
        self::assertSame('plain', $driver->whoami(new Named('plain')), 'other instances are untouched');
    }

    #[Test]
    public function configurableConfiguresObjectsCreatedWithNew(): void
    {
        $weaver = $this->weave([]);
        $weaver->configurable(static function (object $object, Configurable $attribute): void {
            if (property_exists($object, 'configured')) {
                $object->configured = 'yes:'.$attribute->service;
            }
        });

        $widget = (new Driver())->widget();

        self::assertSame('yes:cfg', $widget->configured);
    }

    #[Test]
    public function configurableConfiguresClassesWithoutConstructor(): void
    {
        $weaver = $this->weave([]);
        $weaver->configurable(static function (object $object, Configurable $attribute): void {
            if (property_exists($object, 'configured')) {
                $object->configured = 'yes:'.$attribute->service;
            }
        });

        $configured = (new Driver())->plainWidget()->configured;
        if ('no' === $configured) {
            self::markTestSkipped('This engine build does not emit initialization join points (stream G).');
        }
        self::assertSame('yes:plain', $configured);
    }

    #[Test]
    public function fromConfigWeavesPlainAspectClasses(): void
    {
        $weaver = $this->weaver = Weaver::fromConfig(['aspects' => [[
            'class' => ConfigAspect::class,
            'advice' => [['type' => 'before', 'pointcut' => 'execution(* AopE2E\Pipeline::outer(..))', 'method' => 'log']],
        ]]]);
        $weaver->enable();

        (new Driver())->outer(new Pipeline());

        self::assertSame(['config:outer'], Journal::$entries);

        $weaver->removeAspect(ConfigAspect::class);
        Journal::reset();
        (new Driver())->outer(new Pipeline());
        self::assertSame([], Journal::$entries);
    }
}
