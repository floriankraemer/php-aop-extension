<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Runtime;

use AOP\Advisor\Advisor;
use AOP\JoinPoint\JoinPointKind;
use AOP\JoinPoint\MethodInvocation;
use AOP\Pointcut\AndPointcut;
use AOP\Runtime\NativeRuntime;
use AOP\Tests\Fixtures\Log;
use AOP\Tests\Fixtures\Runtime\FlowPointcut;
use AOP\Tests\Fixtures\Runtime\KindPointcut;
use AOP\Tests\Fixtures\Runtime\TargetService;
use AOP\Tests\Fixtures\Runtime\Trace;
use AOP\Weaver;
use PHPUnit\Framework\Attributes\Test;

final class ControlFlowTest extends RuntimeTestCase
{
    private function flowWeaver(FlowPointcut $flow, Trace $trace): void
    {
        $weaver = Weaver::fromAspects([], $this->engine);
        $weaver->addAdvisor(new Advisor('flow', null, 1, new AndPointcut(new KindPointcut([JoinPointKind::METHOD_EXECUTION], 'hidden'), $flow), $trace));
        $weaver->enable();
    }

    #[Test]
    public function aJoinPointFeedingACflowIsTrackedWithoutAdvice(): void
    {
        $this->flowWeaver(new FlowPointcut('viaSelf'), new Trace('h'));
        $decision = (new \ReflectionProperty($this->engine, 'matcher'))->getValue($this->engine);
        self::assertIsCallable($decision);
        $descriptor = static fn (string $name): array => ['kind' => 'method-execution', 'class' => TargetService::class, 'scope' => TargetService::class, 'name' => $name, 'static' => false, 'internal' => false, 'caller' => null];

        self::assertSame(NativeRuntime::ADVISE, $decision($descriptor('hidden')));
        self::assertSame(NativeRuntime::TRACK, $decision($descriptor('viaSelf')));
        self::assertSame(0, $decision($descriptor('pub')));
    }

    #[Test]
    public function theStackHoldsTheActiveJoinPointsOutermostFirst(): void
    {
        $flow = new FlowPointcut('viaSelf');
        $service = new TargetService();
        $weaver = Weaver::fromAspects([], $this->engine);
        $weaver->addAdvisor(new Advisor('outer', null, 1, new KindPointcut([JoinPointKind::METHOD_EXECUTION], 'viaSelf'), new Trace('v', function (MethodInvocation $invocation) use ($service) {
            $this->engine->call($service, 'hidden', ['a']);

            return $invocation->proceed();
        })));
        $weaver->addAdvisor(new Advisor('flow', null, 1, new AndPointcut(new KindPointcut([JoinPointKind::METHOD_EXECUTION], 'hidden'), $flow), new Trace('h')));
        $weaver->enable();

        $this->engine->call($service, 'viaSelf', ['x']);

        self::assertContains(['TargetService::viaSelf(..)', 'TargetService::hidden(..)'], $flow->stacks);
    }

    #[Test]
    public function cflowAdviceOnlyRunsInsideTheFlow(): void
    {
        $flow = new FlowPointcut('viaSelf');
        $weaver = Weaver::fromAspects([], $this->engine);
        $weaver->addAdvisor(new Advisor('flow', null, 1, new AndPointcut(new KindPointcut([JoinPointKind::METHOD_EXECUTION], 'hidden'), $flow), new Trace('h')));
        $weaver->addAdvisor(new Advisor('outer', null, 1, new KindPointcut([JoinPointKind::METHOD_EXECUTION], 'viaSelf'), new Trace('v', function (MethodInvocation $invocation) {
            $this->engine->call($invocation->getThis() ?? new TargetService(), 'hidden', ['in']);

            return $invocation->proceed();
        })));
        $weaver->enable();
        $service = new TargetService();

        $this->engine->call($service, 'hidden', ['out']);
        self::assertSame(['hidden'], Log::$entries);

        Log::reset();
        $this->engine->call($service, 'viaSelf', ['x']);
        self::assertSame(['v>', 'h>', 'hidden', 'h<', 'hidden', 'v<'], Log::$entries);
    }

    #[Test]
    public function aFiberStartsWithAnEmptyStackAndLeavesTheOuterStackAlone(): void
    {
        $flow = new FlowPointcut('viaSelf');
        $weaver = Weaver::fromAspects([], $this->engine);
        $weaver->addAdvisor(new Advisor('flow', null, 1, new AndPointcut(new KindPointcut([JoinPointKind::METHOD_EXECUTION], 'hidden'), $flow), new Trace('h')));
        $weaver->addAdvisor(new Advisor('outer', null, 1, new KindPointcut([JoinPointKind::METHOD_EXECUTION], 'viaSelf'), new Trace('v', function (MethodInvocation $invocation) {
            $service = ($invocation->getThis() ?? new TargetService());
            $fiber = new \Fiber(function () use ($service): string {
                $this->engine->call($service, 'hidden', ['in fiber']);
                \Fiber::suspend('suspended');
                $this->engine->call($service, 'hidden', ['in fiber again']);

                return 'done';
            });
            self::assertSame('suspended', $fiber->start());
            $this->engine->call($service, 'hidden', ['outer']);
            $fiber->resume();

            return $invocation->proceed();
        })));
        $weaver->enable();

        $this->engine->call(new TargetService(), 'viaSelf', ['x']);

        // hidden ran with advice only in the outer flow; inside the Fiber the stack is empty, so the cflow did not hold.
        self::assertSame(['v>', 'hidden', 'h>', 'hidden', 'h<', 'hidden', 'hidden', 'v<'], Log::$entries);
        self::assertSame(['TargetService::hidden(..)'], $flow->stacks[0], 'the Fiber sees only its own join point');
        self::assertSame(['TargetService::viaSelf(..)', 'TargetService::hidden(..)'], $flow->stacks[1]);
    }
}
