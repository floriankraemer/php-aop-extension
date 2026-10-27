<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Runtime;

use AOP\Advisor\Advisor;
use AOP\Exception\AspectDefinitionException;
use AOP\JoinPoint\JoinPointKind;
use AOP\Runtime\AdvisedInstance;
use AOP\Tests\Fixtures\Log;
use AOP\Tests\Fixtures\Runtime\Aspect\LogAspect;
use AOP\Tests\Fixtures\Runtime\KindPointcut;
use AOP\Tests\Fixtures\Runtime\TargetService;
use AOP\Tests\Fixtures\Runtime\Trace;
use AOP\Weaver;
use PHPUnit\Framework\Attributes\Test;

final class PerInstanceAdviceTest extends RuntimeTestCase
{
    private function advisor(string $id, string $label, int $order = 1): Advisor
    {
        return new Advisor($id, null, $order, new KindPointcut([JoinPointKind::METHOD_EXECUTION], 'pub'), new Trace($label));
    }

    #[Test]
    public function advisorsOnOneInstanceDoNotAffectOthers(): void
    {
        $weaver = Weaver::fromAspects([], $this->engine);
        $weaver->enable();
        $advised = new TargetService();
        $other = new TargetService();
        $this->engine->call($other, 'pub', ['warm up the decision cache']);
        Log::reset();

        $weaver->advised($advised)->addAdvisor($this->advisor('mine', 'mine'));

        $this->engine->call($advised, 'pub', ['x']);
        self::assertSame(['mine>', 'pub', 'mine<'], Log::$entries);
        Log::reset();
        $this->engine->call($other, 'pub', ['y']);
        self::assertSame(['pub'], Log::$entries);
    }

    #[Test]
    public function perInstanceAdvisorsAreOrderedWithTheGlobalOnes(): void
    {
        $weaver = Weaver::fromAspects([new LogAspect()], $this->engine);
        $weaver->enable();
        $service = new TargetService();
        $weaver->advised($service)->addAdvisor($this->advisor('inner', 'inner', PHP_INT_MAX));
        $weaver->advised($service)->addAdvisor($this->advisor('outer', 'outer', -1));

        $this->engine->call($service, 'pub', ['x']);

        self::assertSame(['outer>', 'around>pub', 'before', 'inner>', 'pub', 'inner<', 'after', 'around<', 'outer<'], Log::$entries);
    }

    #[Test]
    public function removingAnAdvisorStopsTheAdvice(): void
    {
        $weaver = Weaver::fromAspects([], $this->engine);
        $weaver->enable();
        $service = new TargetService();
        $advised = $weaver->advised($service);
        $advised->addAdvisor($this->advisor('mine', 'mine'));
        $this->engine->call($service, 'pub', ['x']);

        $advised->removeAdvisor('mine');
        Log::reset();
        $this->engine->call($service, 'pub', ['x']);

        self::assertSame(['pub'], Log::$entries);
        self::assertSame([], $advised->advisors());
    }

    #[Test]
    public function theSameInstanceIsReturnedAndListsItsAdvisors(): void
    {
        $weaver = Weaver::fromAspects([], $this->engine);
        $service = new TargetService();

        $advised = $weaver->advised($service);
        $advised->addAdvisor($this->advisor('a', 'a'));

        self::assertInstanceOf(AdvisedInstance::class, $advised);
        self::assertSame($advised, $weaver->advised($service));
        self::assertSame($service, $advised->instance());
        self::assertSame(['a'], array_map(static fn (Advisor $a): string => $a->id, $advised->advisors()));
        self::assertFalse($advised->isFrozen());
    }

    #[Test]
    public function aFrozenInstanceRejectsChanges(): void
    {
        $weaver = Weaver::fromAspects([], $this->engine);
        $advised = $weaver->advised(new TargetService());
        $advised->freeze();

        self::assertTrue($advised->isFrozen());
        $this->expectException(\AOP\Exception\WeaverFrozenException::class);
        $advised->addAdvisor($this->advisor('a', 'a'));
    }

    #[Test]
    public function perInstanceAdvisorsApplyToEveryMethodOfTheClassOfThatInstance(): void
    {
        $weaver = Weaver::fromAspects([], $this->engine);
        $weaver->enable();
        $service = new TargetService();
        $weaver->advised($service)->addAdvisor(new Advisor('all', null, 1, new KindPointcut([JoinPointKind::METHOD_EXECUTION]), new Trace('all')));

        $this->engine->call($service, 'guarded');
        $this->engine->call($service, 'sealed');

        self::assertSame(['all>', 'all<', 'all>', 'all<'], Log::$entries);
    }

    #[Test]
    public function anAdvisorIdCanOnlyBeAddedOncePerInstance(): void
    {
        $advised = Weaver::fromAspects([], $this->engine)->advised(new TargetService());
        $advised->addAdvisor($this->advisor('dup', 'one'));

        $this->expectException(AspectDefinitionException::class);
        $this->expectExceptionMessage('already has an advisor "dup"');

        $advised->addAdvisor($this->advisor('dup', 'two'));
    }
}
