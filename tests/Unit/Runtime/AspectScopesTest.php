<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Runtime;

use AOP\Advisor\Advisor;
use AOP\Exception\AspectDefinitionException;
use AOP\JoinPoint\JoinPointKind;
use AOP\JoinPoint\MethodInvocation;
use AOP\Tests\Fixtures\Log;
use AOP\Tests\Fixtures\Runtime\Aspect\PerCflowAspect;
use AOP\Tests\Fixtures\Runtime\Aspect\PerCflowBelowAspect;
use AOP\Tests\Fixtures\Runtime\Aspect\PerTargetAspect;
use AOP\Tests\Fixtures\Runtime\Aspect\PerTargetRestrictedAspect;
use AOP\Tests\Fixtures\Runtime\Aspect\PerThisAspect;
use AOP\Tests\Fixtures\Runtime\Aspect\PerTypeWithinAspect;
use AOP\Tests\Fixtures\Runtime\KindPointcut;
use AOP\Tests\Fixtures\Runtime\TargetService;
use AOP\Tests\Fixtures\Runtime\TargetServiceChild;
use AOP\Tests\Fixtures\Runtime\Trace;
use AOP\Weaver;
use PHPUnit\Framework\Attributes\Test;

final class AspectScopesTest extends RuntimeTestCase
{
    /**
     * @return list<string> the "Name:id" entries of the advice that ran
     */
    private function ids(string $name): array
    {
        return array_values(array_filter(Log::$entries, static fn (string $e): bool => str_starts_with($e, $name.':')));
    }

    #[Test]
    public function perThisCreatesOneInstancePerExecutingObject(): void
    {
        Weaver::fromAspects([new PerThisAspect()], $this->engine)->enable();
        $a = new TargetService();
        $b = new TargetService();

        $this->engine->call($a, 'pub', ['1']);
        $this->engine->call($a, 'pub', ['2']);
        $this->engine->call($b, 'pub', ['3']);

        $ids = $this->ids('PerThisAspect');
        self::assertCount(3, $ids);
        self::assertSame($ids[0], $ids[1]);
        self::assertNotSame($ids[0], $ids[2]);
    }

    #[Test]
    public function perTargetCreatesOneInstancePerTargetObject(): void
    {
        Weaver::fromAspects([new PerTargetAspect()], $this->engine)->enable();
        $a = new TargetService();
        $b = new TargetService();

        $this->engine->call($a, 'pub', ['1']);
        $this->engine->call($b, 'pub', ['2']);
        $this->engine->call($a, 'sealed');

        $ids = $this->ids('PerTargetAspect');
        self::assertSame($ids[0], $ids[2]);
        self::assertNotSame($ids[0], $ids[1]);
    }

    #[Test]
    public function staticMembersAndFunctionsShareTheRegisteredInstance(): void
    {
        $aspect = new PerThisAspect();
        Weaver::fromAspects([$aspect], $this->engine)->enable();

        $this->engine->call(TargetService::class, 'make');
        $this->engine->call(TargetService::class, 'make');
        $this->engine->call(new TargetService(), 'pub', ['x']);

        $ids = $this->ids('PerThisAspect');
        self::assertSame('PerThisAspect:'.spl_object_id($aspect), $ids[0]);
        self::assertSame($ids[0], $ids[1]);
        self::assertNotSame($ids[0], $ids[2]);
    }

    #[Test]
    public function aPerTargetRestrictionLimitsTheAdvisedJoinPoints(): void
    {
        Weaver::fromAspects([new PerTargetRestrictedAspect()], $this->engine)->enable();
        $service = new TargetService();

        $this->engine->call($service, 'sealed');
        self::assertSame([], $this->ids('PerTargetRestrictedAspect'));

        $this->engine->call($service, 'pub', ['x']);
        self::assertCount(1, $this->ids('PerTargetRestrictedAspect'));
    }

    private function nestedHidden(Weaver $weaver): void
    {
        $weaver->addAdvisor(new Advisor('nest', null, -100, new KindPointcut([JoinPointKind::METHOD_EXECUTION], 'viaSelf'), new Trace('n', function (MethodInvocation $invocation): mixed {
            $this->engine->call($invocation->getThis() ?? new TargetService(), 'hidden', ['a']);

            return $invocation->proceed();
        })));
    }

    #[Test]
    public function perCflowBindsOneInstanceToEachControlFlowIncludingTheEntry(): void
    {
        $weaver = Weaver::fromAspects([new PerCflowAspect()], $this->engine);
        $this->nestedHidden($weaver);
        $weaver->enable();
        $service = new TargetService();

        $this->engine->call($service, 'hidden', ['outside']);
        self::assertSame(0, \count($this->ids('PerCflowAspect')), 'outside the flow the aspect is not bound');

        $this->engine->call($service, 'viaSelf', ['1']);
        $this->engine->call($service, 'viaSelf', ['2']);

        $ids = $this->ids('PerCflowAspect');
        self::assertCount(4, $ids, 'entry and the nested call, twice');
        self::assertSame($ids[0], $ids[1], 'one instance within a flow');
        self::assertSame($ids[2], $ids[3]);
        self::assertNotSame($ids[0], $ids[2], 'a new flow, a new instance');
    }

    #[Test]
    public function perCflowBelowExcludesTheEntryItself(): void
    {
        $weaver = Weaver::fromAspects([new PerCflowBelowAspect()], $this->engine);
        $this->nestedHidden($weaver);
        $weaver->enable();
        $service = new TargetService();

        $this->engine->call($service, 'viaSelf', ['1']);
        $this->engine->call($service, 'viaSelf', ['2']);

        $ids = $this->ids('PerCflowBelowAspect');
        self::assertCount(2, $ids, 'only the nested hidden() call, not viaSelf itself');
        self::assertNotSame($ids[0], $ids[1]);
    }

    #[Test]
    public function perTypeWithinCreatesOneInstancePerType(): void
    {
        Weaver::fromAspects([new PerTypeWithinAspect()], $this->engine)->enable();

        $this->engine->call(new TargetService(), 'pub', ['1']);
        $this->engine->call(new TargetService(), 'pub', ['2']);
        $this->engine->call(new TargetServiceChild(), 'pub', ['3']);
        $this->engine->call(new TargetServiceChild(), 'make');

        $ids = $this->ids('PerTypeWithinAspect');
        self::assertCount(4, $ids);
        self::assertSame(1, \count(array_unique($ids)), 'pub() and make() are all declared by TargetService, so one type');
    }

    #[Test]
    public function aScopeNeedingAPointcutWithoutOneIsRejected(): void
    {
        $aspect = new #[\AOP\Attribute\Aspect(scope: \AOP\Aspect\AspectScope::PER_CFLOW)] class {
        };

        $this->expectException(AspectDefinitionException::class);
        $this->expectExceptionMessage('needs #[Aspect(pointcut');
        Weaver::fromAspects([$aspect], $this->engine);
    }

    #[Test]
    public function aSingletonWithAScopePointcutIsRejected(): void
    {
        $aspect = new #[\AOP\Attribute\Aspect(pointcut: 'execution(* *::*(..))')] class {
        };

        $this->expectException(AspectDefinitionException::class);
        Weaver::fromAspects([$aspect], $this->engine);
    }
}
