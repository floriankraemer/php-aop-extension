<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Runtime;

use AOP\Advisor\Advisor;
use AOP\Exception\AspectDefinitionException;
use AOP\Exception\WeaverFrozenException;
use AOP\JoinPoint\JoinPointKind;
use AOP\Pointcut\Expression\PointcutExpressionCompiler;
use AOP\Runtime\NativeEngineBridge;
use AOP\Tests\Fixtures\Aspect\IntroductionAspect;
use AOP\Tests\Fixtures\Introduction\Trackable;
use AOP\Tests\Fixtures\Introduction\TrackedImpl;
use AOP\Tests\Fixtures\Log;
use AOP\Tests\Fixtures\Runtime\Aspect\LogAspect;
use AOP\Tests\Fixtures\Runtime\Aspect\ServiceAspect;
use AOP\Tests\Fixtures\Runtime\KindPointcut;
use AOP\Tests\Fixtures\Runtime\TargetService;
use AOP\Tests\Fixtures\Runtime\TargetServiceChild;
use AOP\Tests\Fixtures\Runtime\Trace;
use AOP\Tests\Support\RecordingEngine;
use AOP\Weaver;
use PHPUnit\Framework\Attributes\Test;

final class WeaverManagementTest extends RuntimeTestCase
{
    #[Test]
    public function enableRegistersAndDisableUnregisters(): void
    {
        $weaver = Weaver::fromAspects([new LogAspect()], $this->engine);
        self::assertFalse($weaver->isEnabled());
        self::assertFalse($this->engine->isRegistered());

        $weaver->enable();
        self::assertTrue($weaver->isEnabled());
        self::assertTrue($this->engine->isRegistered());

        $weaver->disable();
        self::assertFalse($weaver->isEnabled());
        $this->engine->call(new TargetService(), 'pub', ['x']);
        self::assertSame(['pub'], Log::$entries);
    }

    #[Test]
    public function everyMutationWhileEnabledResetsTheEngine(): void
    {
        $weaver = Weaver::fromAspects([], $this->engine);
        $weaver->addAspect(new LogAspect());
        self::assertSame(0, $this->engine->resets, 'nothing registered yet');

        $weaver->enable();
        $weaver->addAdvisor(new Advisor('a', null, 1, new KindPointcut([JoinPointKind::METHOD_EXECUTION]), new Trace('a')));
        $weaver->removeAdvisor('a');
        $weaver->registerServiceClass(TargetService::class, 'svc');
        $weaver->registerService(new TargetService(), 'one');
        $weaver->include('AOP\**');
        $weaver->exclude('Foo');
        $weaver->removeAspect(LogAspect::class);

        self::assertSame(7, $this->engine->resets);
    }

    #[Test]
    public function aspectsAndAdvisorsCanBeAddedAndRemovedAtRuntime(): void
    {
        $weaver = Weaver::fromAspects([], $this->engine);
        $weaver->enable();
        $service = new TargetService();

        $this->engine->call($service, 'pub', ['x']);
        self::assertSame(['pub'], Log::$entries);

        $weaver->addAspect(new LogAspect());
        Log::reset();
        $this->engine->call($service, 'pub', ['x']);
        self::assertSame(['around>pub', 'before', 'pub', 'after', 'around<'], Log::$entries);

        $weaver->removeAspect(LogAspect::class);
        Log::reset();
        $this->engine->call($service, 'pub', ['x']);
        self::assertSame(['pub'], Log::$entries);

        $weaver->addAdvisor(new Advisor('mine', null, 1, new KindPointcut([JoinPointKind::METHOD_EXECUTION], 'pub'), new Trace('mine')));
        Log::reset();
        $this->engine->call($service, 'pub', ['x']);
        self::assertSame(['mine>', 'pub', 'mine<'], Log::$entries);

        $weaver->removeAdvisor('mine');
        Log::reset();
        $this->engine->call($service, 'pub', ['x']);
        self::assertSame(['pub'], Log::$entries);
    }

    #[Test]
    public function aSingleAdviceOfAnAspectCanBeRemovedById(): void
    {
        $weaver = Weaver::fromAspects([new LogAspect()], $this->engine);
        $weaver->removeAdvisor(LogAspect::class.'::around');
        $weaver->enable();

        $this->engine->call(new TargetService(), 'pub', ['x']);

        self::assertSame(['before', 'pub', 'after'], Log::$entries);
    }

    #[Test]
    public function advisorsAreListedOutermostFirst(): void
    {
        $weaver = Weaver::fromAspects([new LogAspect()], $this->engine);
        $weaver->addAdvisor(new Advisor('early', null, -5, new KindPointcut([JoinPointKind::METHOD_EXECUTION]), new Trace('e')));

        self::assertSame(['early', LogAspect::class.'::around', LogAspect::class.'::before', LogAspect::class.'::after'], array_map(static fn (Advisor $a): string => $a->id, $weaver->advisors()));
    }

    #[Test]
    public function duplicateRegistrationsAreRejectedAndUnknownRemovalsIgnored(): void
    {
        $weaver = Weaver::fromAspects([new LogAspect()], $this->engine);
        $weaver->addAdvisor(new Advisor('a', null, 1, new KindPointcut([]), new Trace('a')));

        try {
            $weaver->addAspect(new LogAspect());
            self::fail('expected');
        } catch (AspectDefinitionException) {
            // expected
        }
        try {
            $weaver->addAdvisor(new Advisor('a', null, 1, new KindPointcut([]), new Trace('a')));
            self::fail('expected');
        } catch (AspectDefinitionException) {
            // expected
        }
        $weaver->removeAdvisor('unknown');
        $weaver->removeAspect(self::class);

        self::assertCount(4, $weaver->advisors());
    }

    #[Test]
    public function aFrozenWeaverRejectsEveryMutation(): void
    {
        $weaver = Weaver::fromAspects([new LogAspect()], $this->engine);
        $weaver->freeze();
        self::assertTrue($weaver->isFrozen());

        $mutations = [
            fn () => $weaver->addAspect(new ServiceAspect()),
            fn () => $weaver->removeAspect(LogAspect::class),
            fn () => $weaver->addAdvisor(new Advisor('a', null, 1, new KindPointcut([]), new Trace('a'))),
            fn () => $weaver->removeAdvisor('a'),
            fn () => $weaver->registerService(new TargetService(), 's'),
            fn () => $weaver->registerServiceClass(TargetService::class, 's'),
            fn () => $weaver->include('Foo'),
            fn () => $weaver->exclude('Foo'),
            fn () => $weaver->advised(new TargetService())->addAdvisor(new Advisor('a', null, 1, new KindPointcut([]), new Trace('a'))),
        ];
        foreach ($mutations as $index => $mutation) {
            try {
                $mutation();
                self::fail('mutation '.$index.' should be rejected');
            } catch (WeaverFrozenException) {
                // expected
            }
        }

        $weaver->enable();
        self::assertTrue($weaver->isEnabled(), 'a frozen weaver can still be enabled and disabled');
    }

    #[Test]
    public function includeRestrictsWeavingToMatchingTypes(): void
    {
        $weaver = Weaver::fromAspects([new LogAspect()], $this->engine);
        $weaver->include('AOP\Tests\Fixtures\Other\**');
        $weaver->enable();

        $this->engine->call(new TargetService(), 'pub', ['x']);

        self::assertSame(['pub'], Log::$entries);
    }

    #[Test]
    public function includeAcceptsMatchingTypesAndExcludeWins(): void
    {
        $weaver = Weaver::fromAspects([new LogAspect()], $this->engine);
        $weaver->include('AOP\Tests\Fixtures\Runtime\*');
        $weaver->enable();
        $this->engine->call(new TargetService(), 'pub', ['x']);
        self::assertCount(5, Log::$entries);

        $weaver->exclude('AOP\Tests\Fixtures\Runtime\TargetService');
        Log::reset();
        $this->engine->call(new TargetService(), 'pub', ['x']);
        self::assertSame(['pub'], Log::$entries);
    }

    #[Test]
    public function patternsApplyToTheDeclaringTypeOfTheExecutedCode(): void
    {
        $weaver = Weaver::fromAspects([new LogAspect()], $this->engine);
        $weaver->exclude('AOP\Tests\Fixtures\Runtime\TargetServiceChild');
        $weaver->enable();

        $this->engine->call(new TargetServiceChild(), 'pub', ['x']);

        self::assertCount(5, Log::$entries, 'pub() is declared by TargetService, which is not excluded');
    }

    #[Test]
    public function aTrailingPlusIncludesSubtypesInPatterns(): void
    {
        $weaver = Weaver::fromAspects([new LogAspect()], $this->engine);
        $weaver->include('AOP\Tests\Fixtures\Runtime\TargetServiceChild+');
        $weaver->enable();

        $this->engine->call(new TargetService(), 'pub', ['x']);
        self::assertSame(['pub'], Log::$entries, 'the parent is not a subtype of the child');
    }

    #[Test]
    public function serviceNamesFeedTheServiceDesignator(): void
    {
        $weaver = Weaver::fromAspects([new ServiceAspect()], $this->engine);
        $weaver->enable();
        $plain = new TargetService();
        $named = new TargetService();
        $weaver->registerService($named, 'billing.invoices');

        $this->engine->call($plain, 'pub', ['x']);
        self::assertSame(['pub'], Log::$entries);

        Log::reset();
        $this->engine->call($named, 'pub', ['x']);
        self::assertSame(['service-advice', 'pub'], Log::$entries);
    }

    #[Test]
    public function classLevelServicesApplyToEveryInstanceAndSubclass(): void
    {
        $weaver = Weaver::fromAspects([new ServiceAspect()], $this->engine);
        $weaver->registerServiceClass(TargetService::class, 'billing.all');
        $weaver->enable();
        $child = new TargetServiceChild();

        $this->engine->call(new TargetService(), 'pub', ['x']);
        $this->engine->call($child, 'pub', ['y']);
        $this->engine->call(TargetService::class, 'make');

        self::assertSame(['service-advice', 'pub', 'service-advice', 'pub'], array_slice(Log::$entries, 0, 4));
        self::assertCount(5, Log::$entries, 'the static call is advised too');
    }

    #[Test]
    public function aServiceRegisteredAfterEnableTakesEffectAfterTheReset(): void
    {
        $weaver = Weaver::fromAspects([new ServiceAspect()], $this->engine);
        $weaver->enable();
        $service = new TargetService();
        $this->engine->call($service, 'pub', ['x']);

        $weaver->registerService($service, 'billing.late');
        Log::reset();
        $this->engine->call($service, 'pub', ['x']);

        self::assertSame(['service-advice', 'pub'], Log::$entries);
    }

    #[Test]
    public function compilerCompilesProgrammaticExpressions(): void
    {
        $weaver = Weaver::fromAspects([], $this->engine);

        self::assertInstanceOf(PointcutExpressionCompiler::class, $weaver->compiler());
        $weaver->addAdvisor(new Advisor('expr', null, 1, $weaver->compiler()->compile('execution(* AOP\Tests\Fixtures\Runtime\TargetService::pub(..))'), new Trace('expr')));
        $weaver->enable();

        $this->engine->call(new TargetService(), 'pub', ['x']);

        self::assertSame(['expr>', 'pub', 'expr<'], Log::$entries);
    }

    #[Test]
    public function introductionRulesAreHandedToTheEngine(): void
    {
        $engine = new RecordingEngine();
        $weaver = Weaver::fromAspects([new IntroductionAspect()], $engine);

        $weaver->enable();

        self::assertCount(1, $engine->introductions);
        self::assertNotSame([], $engine->introductions[0]);
        self::assertSame($weaver->introductionRules(), $engine->introductions[0]);
        self::assertSame(['typePattern', 'interface', 'defaultImpl'], array_keys($engine->introductions[0][0]));

        $weaver->removeAspect(IntroductionAspect::class);
        self::assertSame([], $engine->introductions[1]);
    }

    #[Test]
    public function declareParentsRulesAreHandedToTheEngineNextToAspectRules(): void
    {
        $engine = new RecordingEngine();
        $weaver = Weaver::fromAspects([new IntroductionAspect()], $engine);
        $weaver->declareParents('App\\Reports\\*', Trackable::class, TrackedImpl::class);

        $weaver->enable();

        $rules = $engine->introductions[0];
        self::assertCount(2, $rules);
        self::assertSame(['typePattern' => 'App\\Reports\\*', 'interface' => Trackable::class, 'defaultImpl' => TrackedImpl::class], $rules[0]);
        self::assertSame($weaver->introductionRules(), $rules);

        $weaver->declareParents('App\\Other\\*', Trackable::class, TrackedImpl::class);
        self::assertCount(3, $engine->introductions[1]);
    }

    #[Test]
    public function declareParentsRejectsAnImplementationThatDoesNotImplementTheInterface(): void
    {
        $this->expectException(AspectDefinitionException::class);
        $this->expectExceptionMessage('must be a class implementing');

        (new Weaver(new RecordingEngine()))->declareParents('App\\*', Trackable::class, Log::class);
    }

    #[Test]
    public function declareParentsIsRejectedWhenFrozen(): void
    {
        $weaver = new Weaver(new RecordingEngine());
        $weaver->freeze();

        $this->expectException(WeaverFrozenException::class);
        $weaver->declareParents('App\\*', Trackable::class, TrackedImpl::class);
    }

    #[Test]
    public function theNativeBridgeFailsClearlyWithoutTheExtension(): void
    {
        if (NativeEngineBridge::available()) {
            self::markTestSkipped('The aop extension is loaded.');
        }
        $weaver = Weaver::fromAspects([new LogAspect()]);

        $this->expectException(AspectDefinitionException::class);
        $this->expectExceptionMessage('ext-aop');
        $weaver->enable();
    }

    #[Test]
    public function declareParentsRejectsAnInterfaceThatIsNotOne(): void
    {
        $this->expectException(AspectDefinitionException::class);
        $this->expectExceptionMessage('"AOP\\Tests\\Fixtures\\Log" is not an interface');

        (new Weaver(new RecordingEngine()))->declareParents('App\\*', Log::class, TrackedImpl::class);
    }

    #[Test]
    public function disablingAWeaverThatWasNeverEnabledLeavesTheEngineAlone(): void
    {
        $engine = new RecordingEngine();
        $engine->register(static fn (): int => 0, static fn (): null => null);
        $weaver = new Weaver($engine);

        $weaver->disable();

        self::assertFalse($weaver->isEnabled());
        self::assertTrue($engine->fake->isRegistered(), 'only an enabled weaver unregisters');
    }
}
