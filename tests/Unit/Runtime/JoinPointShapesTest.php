<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Runtime;

use AOP\Advisor\Advisor;
use AOP\JoinPoint\JoinPointKind;
use AOP\JoinPoint\MethodInvocation;
use AOP\Tests\Fixtures\Log;
use AOP\Tests\Fixtures\Runtime\KindPointcut;
use AOP\Tests\Fixtures\Runtime\TargetService;
use AOP\Tests\Fixtures\Runtime\Trace;
use AOP\Weaver;
use PHPUnit\Framework\Attributes\Test;

use function AOP\Tests\Fixtures\Runtime\shout;

final class JoinPointShapesTest extends RuntimeTestCase
{
    /**
     * @param list<JoinPointKind> $kinds
     */
    private function weave(Trace $trace, array $kinds, string $name = '*', string $id = 'trace'): Weaver
    {
        $weaver = Weaver::fromAspects([], $this->engine);
        $weaver->addAdvisor(new Advisor($id, null, 1, new KindPointcut($kinds, $name), $trace));
        $weaver->enable();

        return $weaver;
    }

    #[Test]
    public function privateMethodsAreAdvised(): void
    {
        $this->weave($trace = new Trace('p'), [JoinPointKind::METHOD_EXECUTION], 'hidden');

        self::assertSame('hidden:a', $this->engine->call(new TargetService(), 'hidden', ['a']));
        self::assertSame(['p>', 'hidden', 'p<'], Log::$entries);
        self::assertSame('method-execution(TargetService::hidden(..))', $trace->seen[0]->getStaticPart()->toShortString());
    }

    #[Test]
    public function protectedFinalAndStaticMethodsAreAdvised(): void
    {
        $this->weave($trace = new Trace('x'), [JoinPointKind::METHOD_EXECUTION]);
        $service = new TargetService();

        self::assertSame('guarded', $this->engine->call($service, 'guarded'));
        self::assertSame('sealed', $this->engine->call($service, 'sealed'));
        self::assertSame('made:cup', $this->engine->call(TargetService::class, 'make', ['cup']));

        self::assertCount(3, $trace->seen);
        $static = $trace->seen[2];
        self::assertNull($static->getThis());
        self::assertNull($static->getTarget());
        self::assertSame($service, $trace->seen[0]->getTarget());
    }

    #[Test]
    public function constructorsAreAdvisedAsConstructorExecution(): void
    {
        $this->weave($trace = new Trace('c'), [JoinPointKind::CONSTRUCTOR_EXECUTION]);
        $service = (new \ReflectionClass(TargetService::class))->newInstanceWithoutConstructor();

        $this->engine->call($service, '__construct', ['named']);

        self::assertSame(JoinPointKind::CONSTRUCTOR_EXECUTION, $trace->seen[0]->getKind());
        self::assertSame('constructor-execution(TargetService::__construct(..))', $trace->seen[0]->getStaticPart()->toShortString());
        self::assertSame(['named'], $trace->args[0]);
        self::assertSame('named', $service->label);
    }

    #[Test]
    public function functionsAreAdvisedWithoutThisOrTarget(): void
    {
        $this->weave($trace = new Trace('f'), [JoinPointKind::FUNCTION_EXECUTION]);

        self::assertSame('HI!', $this->engine->callFunction('AOP\Tests\Fixtures\Runtime\shout', ['hi']));

        $jp = $trace->seen[0];
        self::assertNull($jp->getThis());
        self::assertNull($jp->getTarget());
        self::assertSame(['hi', '!'], $jp->getArgs(), 'the default of $suffix is filled in');
        self::assertSame('function-execution(shout(..))', $jp->getStaticPart()->toShortString());
        self::assertSame('AOP\Tests\Fixtures\Runtime\shout', $jp->getSignature()->getName());
    }

    #[Test]
    public function internalFunctionsAreAdvised(): void
    {
        $this->weave($trace = new Trace('i'), [JoinPointKind::FUNCTION_EXECUTION], 'str_repeat');

        self::assertSame('abab', $this->engine->callFunction('str_repeat', ['ab', 2]));
        self::assertSame(['ab', 2], $trace->args[0]);
    }

    #[Test]
    public function aroundAdviceCanReplaceTheArgumentsOfAFunction(): void
    {
        $this->weave(new Trace('f', static fn (MethodInvocation $i): mixed => $i->proceed(['bye', '?'])), [JoinPointKind::FUNCTION_EXECUTION], 'AOP\Tests\Fixtures\Runtime\shout');

        self::assertSame('BYE?', $this->engine->callFunction('AOP\Tests\Fixtures\Runtime\shout', ['hi', '?']));
    }

    #[Test]
    public function callJoinPointsWrapExecutionJoinPointsWhenTheCallerIsKnown(): void
    {
        $weaver = Weaver::fromAspects([], $this->engine);
        $weaver->addAdvisor(new Advisor('call', null, 1, new KindPointcut([JoinPointKind::METHOD_CALL], 'pub'), $call = new Trace('call')));
        $weaver->addAdvisor(new Advisor('exec', null, 2, new KindPointcut([JoinPointKind::METHOD_EXECUTION], 'pub'), $exec = new Trace('exec')));
        $weaver->enable();
        $caller = new TargetService();
        $callerInfo = ['class' => TargetService::class, 'scope' => TargetService::class, 'function' => 'viaSelf', 'file' => __FILE__, 'line' => 42, 'object' => $caller];
        $callee = new TargetService();

        $this->engine->call($callee, 'pub', ['x'], $callerInfo);

        self::assertSame(['call>', 'exec>', 'pub', 'exec<', 'call<'], Log::$entries);
        $callJp = $call->seen[0];
        self::assertSame(JoinPointKind::METHOD_CALL, $callJp->getKind());
        self::assertSame($caller, $callJp->getThis(), 'this of a call is the caller');
        self::assertSame($callee, $callJp->getTarget());
        self::assertSame(42, $callJp->getSourceLocation()?->line);
        self::assertSame(__FILE__, $callJp->getSourceLocation()->file);
        self::assertSame('method-call(TargetService::pub(..))', $callJp->getStaticPart()->toShortString());
        self::assertSame('TargetService::viaSelf(..)', $callJp->getEnclosingStaticPart()?->signature->toShortString());
        self::assertSame($callee, $exec->seen[0]->getThis(), 'this of an execution is the executing object');
    }

    #[Test]
    public function callAdviceDoesNotRunWithoutAKnownCaller(): void
    {
        $this->weave($call = new Trace('call'), [JoinPointKind::METHOD_CALL], 'pub');

        $this->engine->call(new TargetService(), 'pub', ['x']);

        self::assertSame(['pub'], Log::$entries);
        self::assertSame([], $call->seen);
    }

    #[Test]
    public function callAdviceCanChangeTheArgumentsTheExecutionSees(): void
    {
        $weaver = Weaver::fromAspects([], $this->engine);
        $weaver->addAdvisor(new Advisor('call', null, 1, new KindPointcut([JoinPointKind::METHOD_CALL], 'pub'), new Trace('call', static fn (MethodInvocation $i): mixed => $i->proceed(['changed']))));
        $weaver->addAdvisor(new Advisor('exec', null, 2, new KindPointcut([JoinPointKind::METHOD_EXECUTION], 'pub'), $exec = new Trace('exec')));
        $weaver->enable();

        $result = $this->engine->call(new TargetService(), 'pub', ['orig'], ['class' => null, 'scope' => null, 'function' => null, 'file' => __FILE__, 'line' => 1, 'object' => null]);

        self::assertSame('pub:changed', $result);
        self::assertSame([['changed']], $exec->args);
    }

    #[Test]
    public function callerSensitiveAdvisorsAreDecidedPerCallerFunction(): void
    {
        $weaver = Weaver::fromAspects([], $this->engine);
        $weaver->addAdvisor(new Advisor('call', null, 1, new KindPointcut([JoinPointKind::METHOD_CALL], 'pub', 'viaSelf', true), $call = new Trace('call')));
        $weaver->enable();
        $callee = new TargetService();
        $from = static fn (string $function): array => ['class' => TargetService::class, 'scope' => TargetService::class, 'function' => $function, 'file' => __FILE__, 'line' => 1, 'object' => null];

        $this->engine->call($callee, 'pub', ['a'], $from('viaSelf'));
        $this->engine->call($callee, 'pub', ['b'], $from('callGuarded'));
        $this->engine->call($callee, 'pub', ['c'], $from('viaSelf'));

        self::assertCount(2, $call->seen);
        self::assertSame(3, $this->engine->matcherCalls, 'one caller-less probe plus one answer per distinct caller function');
    }

    #[Test]
    public function argumentsAreNormalizedToDeclaredParametersWithDefaultsAndTheVariadicTail(): void
    {
        $this->weave($trace = new Trace('n'), [JoinPointKind::METHOD_EXECUTION], '*');
        $service = new TargetService();

        $this->engine->call($service, 'defaults', [1]);
        $this->engine->call($service, 'defaults', [1, 2, 'x']);
        $this->engine->call($service, 'join', ['-', 'a', 'b']);
        $this->engine->call($service, 'join', ['-', 'a', 'k' => 'b']);
        $this->engine->call($service, 'join', []);

        self::assertSame([[1, 5, 'c'], [1, 2, 'x'], ['-', 'a', 'b'], ['-', 'a', 'k' => 'b'], [',']], $trace->args);
    }

    #[Test]
    public function normalizedDefaultsReachTheOriginal(): void
    {
        $this->weave(new Trace('n'), [JoinPointKind::METHOD_EXECUTION]);

        self::assertSame('1/5/c', $this->engine->call(new TargetService(), 'defaults', [1]));
        self::assertSame('a-b', $this->engine->call(new TargetService(), 'join', ['-', 'a', 'b']));
    }

    #[Test]
    public function byReferenceArgumentsWriteThroughToTheCaller(): void
    {
        $this->weave(new Trace('r'), [JoinPointKind::METHOD_EXECUTION], 'bump');
        $counter = 1;

        $this->engine->call(new TargetService(), 'bump', [&$counter]);

        self::assertSame(2, $counter);
    }

    #[Test]
    public function byReferenceWriteThroughSurvivesReplacedArguments(): void
    {
        $this->weave(new Trace('r', static fn (MethodInvocation $i): mixed => $i->proceed([10])), [JoinPointKind::METHOD_EXECUTION], 'bump');
        $counter = 1;

        $this->engine->call(new TargetService(), 'bump', [&$counter]);

        self::assertSame(11, $counter);
    }

    #[Test]
    public function aroundCanSkipTheOriginal(): void
    {
        $this->weave(new Trace('s', static fn (): string => 'skipped'), [JoinPointKind::METHOD_EXECUTION], 'pub');

        self::assertSame('skipped', $this->engine->call(new TargetService(), 'pub', ['x']));
        self::assertSame(['s>', 's<'], Log::$entries);
    }

    #[Test]
    public function aStaticReturnTypeIsCheckedAgainstTheCalledClass(): void
    {
        $this->weave(new Trace('s', static fn (): object => new \stdClass()), [JoinPointKind::METHOD_EXECUTION], 'self');

        $this->expectException(\AOP\Exception\AdviceReturnException::class);
        $this->engine->call(new TargetService(), 'self');
    }

    #[Test]
    public function exceptionsPropagateUnchanged(): void
    {
        $this->weave(new Trace('e'), [JoinPointKind::METHOD_EXECUTION], 'fail');

        try {
            $this->engine->call(new TargetService(), 'fail');
            self::fail('expected');
        } catch (\LogicException $e) {
            self::assertSame('failed', $e->getMessage());
        }
        self::assertSame(['e>', 'e<'], Log::$entries);
    }

    #[Test]
    public function functionHelperIsLoaded(): void
    {
        self::assertSame('A!', shout('a'));
    }

    #[Test]
    public function aCallFromAPlainFunctionIsEnclosedByThatFunction(): void
    {
        $this->weave($call = new Trace('call'), [JoinPointKind::METHOD_CALL], 'pub');
        $callerInfo = ['class' => null, 'scope' => null, 'function' => 'AOP\Tests\Fixtures\Runtime\shout', 'file' => __FILE__, 'line' => 7, 'object' => null];

        $this->engine->call(new TargetService(), 'pub', ['x'], $callerInfo);

        $callJp = $call->seen[0];
        self::assertSame('function-execution(shout(..))', $callJp->getEnclosingStaticPart()?->toShortString());
        self::assertNull($callJp->getThis(), 'a function has no this');
    }
}
