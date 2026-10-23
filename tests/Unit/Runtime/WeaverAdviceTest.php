<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Runtime;

use AOP\Exception\AdviceReturnException;
use AOP\Runtime\AopContext;
use AOP\Tests\Fixtures\Aspect\NamedPointcutAspect;
use AOP\Tests\Fixtures\Aspect\RecordingAspectA;
use AOP\Tests\Fixtures\Aspect\RecordingAspectB;
use AOP\Tests\Fixtures\Log;
use AOP\Tests\Fixtures\Runtime\Aspect\BindingAspect;
use AOP\Tests\Fixtures\Runtime\Aspect\LogAspect;
use AOP\Tests\Fixtures\Runtime\Aspect\WrongReturnAspect;
use AOP\Tests\Fixtures\Runtime\TargetService;
use AOP\Tests\Fixtures\Service\Greeter;
use AOP\Weaver;
use PHPUnit\Framework\Attributes\Test;

final class WeaverAdviceTest extends RuntimeTestCase
{
    #[Test]
    public function twoAspectsFiveAdviceTypesRunInSpecOrder(): void
    {
        Weaver::fromAspects([new RecordingAspectB(), new RecordingAspectA()], $this->engine)->enable();

        self::assertSame('Hello World', $this->engine->call(new Greeter(), 'greet', ['World']));

        self::assertSame([
            'A.around>', 'A.before',
            'B.around>', 'B.before',
            'target',
            'B.afterReturning', 'B.after', 'B.around<',
            'A.afterReturning', 'A.after', 'A.around<',
        ], Log::$entries);
    }

    #[Test]
    public function exceptionPathRunsAfterThrowingAndAfterAndRethrows(): void
    {
        Weaver::fromAspects([new RecordingAspectA(), new RecordingAspectB()], $this->engine)->enable();

        try {
            $this->engine->call(new Greeter(), 'fail');
            self::fail('expected exception');
        } catch (\RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        self::assertSame([
            'A.around>', 'A.before',
            'B.around>', 'B.before',
            'target',
            'B.afterThrowing', 'B.after', 'B.around<',
            'A.afterThrowing', 'A.after', 'A.around<',
        ], Log::$entries);
    }

    #[Test]
    public function theMatcherDecidesOncePerJoinPointAndTheChainIsCached(): void
    {
        Weaver::fromAspects([new RecordingAspectA()], $this->engine)->enable();
        $greeter = new Greeter();

        $this->engine->call($greeter, 'greet', ['a']);
        $this->engine->call($greeter, 'greet', ['b']);
        $this->engine->call($greeter, 'greet', ['c']);

        self::assertSame(1, $this->engine->matcherCalls);
    }

    #[Test]
    public function withoutAMatchingAdvisorTheTargetRunsDirectly(): void
    {
        Weaver::fromAspects([], $this->engine)->enable();

        self::assertSame('Hello X', $this->engine->call(new Greeter(), 'greet', ['X']));
        self::assertSame(['target'], Log::$entries);
    }

    #[Test]
    public function runtimePointcutBindingsReachAdvice(): void
    {
        Weaver::fromAspects([new NamedPointcutAspect()], $this->engine)->enable();

        $this->engine->call(new Greeter(), 'greet', ['Ann']);

        self::assertSame(['N.local', 'N.name=Ann', 'target'], Log::$entries);
    }

    #[Test]
    public function argsBindingReachesAdviceOnTheNativeRuntime(): void
    {
        Weaver::fromAspects([new BindingAspect()], $this->engine)->enable();

        $this->engine->call(new TargetService(), 'pub', ['Zed']);

        self::assertSame(['name=Zed', 'pub'], Log::$entries);
    }

    #[Test]
    public function adviceSeesTheJoinPointOfTheExecution(): void
    {
        $aspect = new LogAspect();
        Weaver::fromAspects([$aspect], $this->engine)->enable();
        $service = new TargetService();

        $this->engine->call($service, 'pub', ['x']);

        $jp = $aspect->seen[0];
        self::assertSame($service, $jp->getThis());
        self::assertSame($service, $jp->getTarget());
        self::assertSame(['x'], $jp->getArgs());
        self::assertSame('method-execution(TargetService::pub(..))', $jp->getStaticPart()->toShortString());
        self::assertNotNull($jp->getSourceLocation()?->line);
    }

    #[Test]
    public function aroundResultsAreCheckedAgainstTheDeclaredReturnType(): void
    {
        Weaver::fromAspects([new WrongReturnAspect()], $this->engine)->enable();

        $this->expectException(AdviceReturnException::class);
        $this->expectExceptionMessage('WrongReturnAspect::around');
        $this->engine->call(new TargetService(), 'count');
    }

    #[Test]
    public function aroundMayReturnAnythingForUntypedAndMatchingResults(): void
    {
        Weaver::fromAspects([new LogAspect()], $this->engine)->enable();

        self::assertSame(3, $this->engine->call(new TargetService(), 'count'));
    }

    #[Test]
    public function aopContextExposesTheJoinPointDuringTheCallOnly(): void
    {
        Weaver::fromAspects([new LogAspect()], $this->engine)->enable();
        self::assertNull(AopContext::currentJoinPoint());

        $service = new TargetService();
        $this->engine->call($service, 'pub', ['x']);

        self::assertNull(AopContext::currentJoinPoint());
    }

    #[Test]
    public function aopContextIsSetInsideAdvice(): void
    {
        $seen = [];
        $trace = new \AOP\Tests\Fixtures\Runtime\Trace('t', static function (\AOP\JoinPoint\MethodInvocation $invocation) use (&$seen) {
            $seen[] = AopContext::currentJoinPoint()?->toShortString();
            $seen[] = get_debug_type(AopContext::currentObject());

            return $invocation->proceed();
        });
        $weaver = Weaver::fromAspects([], $this->engine);
        $weaver->addAdvisor(new \AOP\Advisor\Advisor('t', null, 1, new \AOP\Tests\Fixtures\Runtime\KindPointcut([\AOP\JoinPoint\JoinPointKind::METHOD_EXECUTION], 'pub'), $trace));
        $weaver->enable();

        $this->engine->call(new TargetService(), 'pub', ['x']);

        self::assertSame(['TargetService::pub(..)', TargetService::class], $seen);
    }
}
