<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Runtime;

use AOP\Aspect\AspectScope;
use AOP\Exception\AspectDefinitionException;
use AOP\JoinPoint\JoinPointData;
use AOP\JoinPoint\JoinPointKind;
use AOP\JoinPoint\MethodInvocation;
use AOP\JoinPoint\MethodSignature;
use AOP\JoinPoint\StaticPart;
use AOP\Runtime\ControlFlowStack;
use AOP\Runtime\ScopedAspectResolver;
use AOP\Tests\Fixtures\Jp;
use AOP\Tests\Fixtures\Runtime\Aspect\PerThisAspect;
use AOP\Tests\Fixtures\Runtime\TargetService;
use AOP\Tests\Fixtures\Service\UserService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScopedAspectResolverTest extends TestCase
{
    private ControlFlowStack $flow;
    private ScopedAspectResolver $resolver;

    protected function setUp(): void
    {
        $this->flow = new ControlFlowStack();
        $this->resolver = new ScopedAspectResolver($this->flow);
    }

    #[Test]
    public function aFactoryIsCalledLazilyOnceForTheSharedInstance(): void
    {
        $calls = 0;
        $this->resolver->register(PerThisAspect::class, static function () use (&$calls): PerThisAspect {
            ++$calls;

            return new PerThisAspect();
        }, AspectScope::SINGLETON);

        self::assertSame(0, $calls);
        $first = $this->resolver->get(PerThisAspect::class);
        self::assertSame($first, $this->resolver->get(PerThisAspect::class, new \stdClass()));
        self::assertSame(1, $calls);
    }

    #[Test]
    public function aFactoryIsCalledOncePerScopeKey(): void
    {
        $this->resolver->register(PerThisAspect::class, static fn (): PerThisAspect => new PerThisAspect(), AspectScope::PER_THIS);
        $a = new \stdClass();
        $b = new \stdClass();

        $forA = $this->resolver->get(PerThisAspect::class, $a);

        self::assertSame($forA, $this->resolver->get(PerThisAspect::class, $a));
        self::assertNotSame($forA, $this->resolver->get(PerThisAspect::class, $b));
        self::assertNotSame($forA, $this->resolver->get(PerThisAspect::class));
    }

    #[Test]
    public function aFactoryReturningTheWrongTypeIsRejected(): void
    {
        $this->resolver->register(PerThisAspect::class, static fn (): object => new \stdClass(), AspectScope::SINGLETON);

        $this->expectException(AspectDefinitionException::class);
        $this->expectExceptionMessage('returned stdClass');

        $this->resolver->get(PerThisAspect::class);
    }

    #[Test]
    public function aMissingInstanceIsReportedAndRemovingForgetsTheAspect(): void
    {
        $this->resolver->register(PerThisAspect::class, new PerThisAspect(), AspectScope::SINGLETON);
        self::assertTrue($this->resolver->has(PerThisAspect::class));

        $this->resolver->remove(PerThisAspect::class);

        self::assertFalse($this->resolver->has(PerThisAspect::class));
        $this->expectException(AspectDefinitionException::class);
        $this->expectExceptionMessage('No instance registered');
        $this->resolver->get(PerThisAspect::class);
    }

    #[Test]
    public function perInstanceScopesCloneThePrototypeAsItWasWhenRegistered(): void
    {
        $registered = new PerThisAspect();
        $this->resolver->register(PerThisAspect::class, $registered, AspectScope::PER_TARGET);

        self::assertSame($registered, $this->resolver->get(PerThisAspect::class), 'without a scope object the registered instance is used');
        self::assertNotSame($registered, $this->resolver->get(PerThisAspect::class, new \stdClass()));
    }

    #[Test]
    public function aPerInstanceCloneStartsFromThePrototypeNotFromLaterChangesToTheRegisteredObject(): void
    {
        $registered = new \stdClass();
        $registered->state = 'as registered';
        $this->resolver->register(\stdClass::class, $registered, AspectScope::PER_THIS);
        $registered->state = 'changed later';

        self::assertSame('as registered', $this->resolver->get(\stdClass::class, new \stdClass())->state ?? null);
    }

    #[Test]
    public function registeringAgainDropsTheInstancesCreatedForEarlierScopeKeys(): void
    {
        $key = new \stdClass();
        $first = new \stdClass();
        $first->state = 'first';
        $second = new \stdClass();
        $second->state = 'second';
        $this->resolver->register(\stdClass::class, $first, AspectScope::PER_THIS);
        self::assertSame('first', $this->resolver->get(\stdClass::class, $key)->state ?? null);

        $this->resolver->register(\stdClass::class, $second, AspectScope::PER_THIS);

        self::assertSame('second', $this->resolver->get(\stdClass::class, $key)->state ?? null);
    }

    #[Test]
    public function aWrapperAroundAdviceIsOptional(): void
    {
        $joinPoint = Jp::invocation(new TargetService(), 'hidden');
        $aspect = new PerThisAspect();

        self::assertSame('plain', $this->resolver->wrapAdvice($joinPoint, PerThisAspect::class, $aspect, 'note', ['x'], static fn (array $v): string => 'plain'));

        $this->resolver->wrapWith(static fn ($jp, string $class, object $a, string $method, array $values, \Closure $call): string => 'wrapped:'.$class.'::'.$method.':'.(is_string($inner = $call($values)) ? $inner : ''));

        self::assertSame('wrapped:'.PerThisAspect::class.'::note:inner', $this->resolver->wrapAdvice($joinPoint, PerThisAspect::class, $aspect, 'note', ['x'], static fn (array $v): string => 'inner'));
    }

    #[Test]
    public function perCflowUsesTheSharedInstanceOutsideAndOneInstancePerFlowInside(): void
    {
        $this->resolver->register(PerThisAspect::class, new PerThisAspect(), AspectScope::PER_CFLOW);
        $joinPoint = Jp::invocation(new TargetService(), 'hidden');
        $outside = $this->resolver->get(PerThisAspect::class, $joinPoint);

        $this->flow->pushToken(PerThisAspect::class);
        $first = $this->resolver->get(PerThisAspect::class, $joinPoint);
        self::assertSame($first, $this->resolver->get(PerThisAspect::class, $joinPoint));
        $this->flow->pushToken(PerThisAspect::class);
        $second = $this->resolver->get(PerThisAspect::class, $joinPoint);
        $this->flow->popToken(PerThisAspect::class);
        $this->flow->popToken(PerThisAspect::class);

        self::assertNotSame($outside, $first);
        self::assertNotSame($first, $second);
        self::assertSame($outside, $this->resolver->get(PerThisAspect::class, $joinPoint));
    }

    #[Test]
    public function perTypeWithinKeysCallSitesByTheCallerAndExecutionsByTheDeclaringClass(): void
    {
        $this->resolver->register(PerThisAspect::class, new PerThisAspect(), AspectScope::PER_TYPE_WITHIN);
        $service = new TargetService();
        $execution = Jp::invocation($service, 'hidden');
        $caller = new StaticPart(JoinPointKind::METHOD_EXECUTION, MethodSignature::fromReflection(new \ReflectionMethod(UserService::class, 'findUser')));
        $call = new MethodInvocation(
            new JoinPointData(JoinPointKind::METHOD_CALL, null, $service, MethodSignature::fromReflection(new \ReflectionMethod($service, 'hidden')), new StaticPart(JoinPointKind::METHOD_CALL, MethodSignature::fromReflection(new \ReflectionMethod($service, 'hidden'))), $caller),
            [],
            [],
            static fn (array $a): null => null,
        );
        $callWithoutCaller = Jp::invocation($service, 'hidden', kind: JoinPointKind::METHOD_CALL);

        $byExecution = $this->resolver->get(PerThisAspect::class, $execution);
        $byCaller = $this->resolver->get(PerThisAspect::class, $call);

        self::assertSame($byExecution, $this->resolver->get(PerThisAspect::class, Jp::invocation(new TargetService(), 'hidden')));
        self::assertNotSame($byExecution, $byCaller);
        self::assertSame($this->resolver->get(PerThisAspect::class), $this->resolver->get(PerThisAspect::class, $callWithoutCaller), 'no enclosing code: the shared instance');
        self::assertSame($this->resolver->get(PerThisAspect::class), $this->resolver->get(PerThisAspect::class, new \stdClass()), 'a scope object that is not a join point: the shared instance');
    }
}
