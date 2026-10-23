<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Runtime;

use AOP\Advisor\Advisor;
use AOP\JoinPoint\JoinPointKind;
use AOP\Tests\Fixtures\Log;
use AOP\Tests\Fixtures\Runtime\Aspect\LogAspect;
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
}
