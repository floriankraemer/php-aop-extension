<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Runtime;

use AOP\Advisor\Advisor;
use AOP\JoinPoint\JoinPointKind;
use AOP\Tests\Fixtures\Log;
use AOP\Tests\Fixtures\Runtime\Aspect\LogAspect;
use AOP\Tests\Fixtures\Runtime\Aspect\WatchAdviceAspect;
use AOP\Tests\Fixtures\Runtime\KindPointcut;
use AOP\Tests\Fixtures\Runtime\TargetService;
use AOP\Tests\Fixtures\Runtime\Trace;
use AOP\Weaver;
use PHPUnit\Framework\Attributes\Test;

final class AdviceExecutionTest extends RuntimeTestCase
{
    #[Test]
    public function adviceMethodsRunInsideAnAdviceExecutionJoinPoint(): void
    {
        $weaver = Weaver::fromAspects([new LogAspect()], $this->engine);
        $weaver->addAdvisor(new Advisor('watch', null, 1000, new KindPointcut([JoinPointKind::ADVICE_EXECUTION], 'before'), $trace = new Trace('adv')));
        $weaver->enable();

        $this->engine->call(new TargetService(), 'pub', ['x']);

        self::assertSame(['around>pub', 'adv>', 'before', 'adv<', 'pub', 'after', 'around<'], Log::$entries);
        $jp = $trace->seen[0];
        self::assertSame(JoinPointKind::ADVICE_EXECUTION, $jp->getKind());
        self::assertSame('adviceexecution(LogAspect::before(..))', $jp->getSignature()->toShortString());
        self::assertInstanceOf(LogAspect::class, $jp->getThis());
        self::assertSame('method-execution(TargetService::pub(..))', $jp->getEnclosingStaticPart()?->toShortString());
    }

    #[Test]
    public function anAdviceExecutionAdvisorThatMatchesEverythingDoesNotRecurseForever(): void
    {
        $weaver = Weaver::fromAspects([new LogAspect()], $this->engine);
        $weaver->addAdvisor(new Advisor('watch', null, 1000, new KindPointcut([JoinPointKind::ADVICE_EXECUTION]), $trace = new Trace('adv')));
        $weaver->enable();

        $this->engine->call(new TargetService(), 'pub', ['x']);

        self::assertCount(3, $trace->seen, 'around, before and after of LogAspect, each once');
    }

    #[Test]
    public function everyRunOfAnAdviceMethodIsWrappedNotOnlyTheFirst(): void
    {
        $weaver = Weaver::fromAspects([new LogAspect()], $this->engine);
        $weaver->addAdvisor(new Advisor('watch', null, 1000, new KindPointcut([JoinPointKind::ADVICE_EXECUTION]), $trace = new Trace('adv')));
        $weaver->enable();
        $service = new TargetService();

        $this->engine->call($service, 'pub', ['x']);
        $this->engine->call($service, 'pub', ['y']);

        self::assertCount(6, $trace->seen);
    }

    #[Test]
    public function theAdviceExecutionJoinPointPointsAtTheAdviceMethodInItsFile(): void
    {
        $weaver = Weaver::fromAspects([new LogAspect()], $this->engine);
        $weaver->addAdvisor(new Advisor('watch', null, 1000, new KindPointcut([JoinPointKind::ADVICE_EXECUTION], 'before'), $trace = new Trace('adv')));
        $weaver->enable();

        $this->engine->call(new TargetService(), 'pub', ['x']);

        $reflection = new \ReflectionMethod(LogAspect::class, 'before');
        $location = $trace->seen[0]->getSourceLocation();
        self::assertSame($reflection->getFileName(), $location?->file);
        self::assertSame($reflection->getStartLine(), $location->line);
        self::assertSame(LogAspect::class, $location->class);
        self::assertSame('before', $location->function);
    }

    #[Test]
    public function anAdviceDoesNotAdviseItsOwnExecution(): void
    {
        Weaver::fromAspects([new LogAspect(), new WatchAdviceAspect()], $this->engine)->enable();

        $this->engine->call(new TargetService(), 'pub', ['x']);

        self::assertSame(['watch', 'around>pub', 'watch', 'before', 'pub', 'watch', 'after', 'around<'], Log::$entries, 'once for each advice of LogAspect, none for the watcher itself');
    }
}
