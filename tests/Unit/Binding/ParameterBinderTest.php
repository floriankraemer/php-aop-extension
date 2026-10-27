<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Binding;

use AOP\Advice\AdviceDefinition;
use AOP\Aspect\AspectMetadataReader;
use AOP\Binding\BindingPlan;
use AOP\Binding\BoundArguments;
use AOP\Binding\ParameterBinder;
use AOP\Exception\BindingException;
use AOP\JoinPoint\MethodInvocation;
use AOP\Pointcut\Expression\PointcutExpressionCompiler;
use AOP\Tests\Fixtures\Aspect\BindingAspect;
use AOP\Tests\Fixtures\Hierarchy\Audit;
use AOP\Tests\Fixtures\Jp;
use AOP\Tests\Fixtures\Service\UserService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ParameterBinderTest extends TestCase
{
    private function advice(string $method): AdviceDefinition
    {
        foreach ((new AspectMetadataReader())->read(BindingAspect::class)->advices as $advice) {
            if ($advice->methodName === $method) {
                return $advice;
            }
        }

        self::fail('No advice '.$method);
    }

    private function plan(string $method): BindingPlan
    {
        $advice = $this->advice($method);
        $reflection = new \ReflectionMethod(BindingAspect::class, $method);
        $pointcut = (new PointcutExpressionCompiler())->compile(
            $advice->expression,
            ParameterBinder::bindingCandidates($reflection, $advice),
        );

        return (new ParameterBinder())->plan($reflection, $advice, $pointcut);
    }

    private function joinPoint(): MethodInvocation
    {
        return Jp::invocation(new UserService(), 'findUser', [1]);
    }

    #[Test]
    public function candidatesExcludeJoinPointsReturningAndThrowing(): void
    {
        $advice = $this->advice('withArgs');
        self::assertSame(['id' => 'int'], ParameterBinder::bindingCandidates(new \ReflectionMethod(BindingAspect::class, 'withArgs'), $advice));

        $advice = $this->advice('afterReturning');
        self::assertSame([], ParameterBinder::bindingCandidates(new \ReflectionMethod(BindingAspect::class, 'afterReturning'), $advice));

        $advice = $this->advice('audited');
        self::assertSame(['audit' => Audit::class], ParameterBinder::bindingCandidates(new \ReflectionMethod(BindingAspect::class, 'audited'), $advice));
    }

    #[Test]
    public function joinPointMayBeAtAnyPosition(): void
    {
        $jp = $this->joinPoint();
        $plan = $this->plan('withArgs');

        $arguments = $plan->resolve($jp, ['id' => 42]);

        self::assertInstanceOf(BoundArguments::class, $arguments);
        self::assertSame([$jp, 42], $arguments->values);

        $plan = $this->plan('afterReturning');
        self::assertSame(['r', $jp], $plan->resolve($jp, [], 'r')->values);
    }

    #[Test]
    public function returningAndThrowingAreBoundByName(): void
    {
        $jp = $this->joinPoint();
        $e = new \RuntimeException('x');

        self::assertSame(['ok', $jp], $this->plan('afterReturning')->resolve($jp, [], 'ok')->values);
        self::assertSame([$e], $this->plan('afterThrowing')->resolve($jp, [], null, $e)->values);
    }

    #[Test]
    public function throwingTypeRestrictsMatching(): void
    {
        $plan = $this->plan('afterThrowing');

        self::assertTrue($plan->acceptsThrowable(new \RuntimeException()));
        self::assertTrue($plan->acceptsThrowable(new class extends \RuntimeException {}));
        self::assertFalse($plan->acceptsThrowable(new \LogicException()));
        self::assertTrue($this->plan('afterThrowingUntyped')->acceptsThrowable(new \LogicException()));
        self::assertTrue($this->plan('withArgs')->acceptsThrowable(new \LogicException()));
    }

    #[Test]
    public function returningTypeRestrictsMatching(): void
    {
        $plan = $this->plan('afterReturning');
        self::assertTrue($plan->acceptsReturnValue('x'));
        self::assertFalse($plan->acceptsReturnValue(1));
        self::assertFalse($plan->acceptsReturnValue(null));

        $untyped = $this->plan('afterReturningUntyped');
        self::assertTrue($untyped->acceptsReturnValue(null));
        self::assertTrue($untyped->acceptsReturnValue(new \stdClass()));

        $nullable = $this->plan('afterReturningNullable');
        self::assertTrue($nullable->acceptsReturnValue(null));
        self::assertTrue($nullable->acceptsReturnValue(new \ArrayObject()));
        self::assertFalse($nullable->acceptsReturnValue(new \stdClass()));

        self::assertTrue($this->plan('withArgs')->acceptsReturnValue(123));
    }

    #[Test]
    public function throwingNameWithoutMatchingParameterIsRejected(): void
    {
        $this->expectException(BindingException::class);
        $this->expectExceptionMessage('declares throwing: "missing" but has no parameter $missing');

        $this->plan('afterThrowingMissingParameter');
    }

    #[Test]
    public function aroundReceivesTheProceedingJoinPoint(): void
    {
        $jp = $this->joinPoint();

        self::assertSame([$jp], $this->plan('around')->resolve($jp, [])->values);
    }

    #[Test]
    public function bindsAnnotationInstanceByName(): void
    {
        $audit = new Audit('login');

        self::assertSame([$audit], $this->plan('audited')->resolve($this->joinPoint(), ['audit' => $audit])->values);
    }

    #[Test]
    public function staticPartParameterBindsTheJoinPointsStaticPart(): void
    {
        $jp = $this->joinPoint();

        $arguments = $this->plan('withStaticPart')->resolve($jp, ['id' => 5]);

        self::assertSame([$jp->getStaticPart(), 5, $jp], $arguments->values);
    }

    #[Test]
    public function staticPartIsNotABindingCandidate(): void
    {
        $advice = $this->advice('withStaticPart');

        self::assertSame(['id' => 'int'], ParameterBinder::bindingCandidates(new \ReflectionMethod(BindingAspect::class, 'withStaticPart'), $advice));
    }

    #[Test]
    public function unresolvedParameterNamesParameterAndAdviceMethod(): void
    {
        try {
            $this->plan('unresolvable');
            self::fail('Expected BindingException');
        } catch (BindingException $e) {
            self::assertStringContainsString('$unknown', $e->getMessage());
            self::assertStringContainsString(BindingAspect::class.'::unresolvable', $e->getMessage());
        }
    }

    #[Test]
    public function aroundWithoutProceedingJoinPointIsRejected(): void
    {
        $this->expectException(BindingException::class);
        $this->expectExceptionMessage(BindingAspect::class.'::aroundWithoutProceedingJoinPoint');

        $this->plan('aroundWithoutProceedingJoinPoint');
    }

    #[Test]
    public function nonAroundAdviceMustNotDeclareProceedingJoinPoint(): void
    {
        $this->expectException(BindingException::class);
        $this->expectExceptionMessage(BindingAspect::class.'::beforeWithProceedingJoinPoint');

        $this->plan('beforeWithProceedingJoinPoint');
    }

    #[Test]
    public function returningNameWithoutMatchingParameterIsRejected(): void
    {
        $this->expectException(BindingException::class);
        $this->expectExceptionMessage('$missing');

        $this->plan('afterReturningMissingParameter');
    }

    #[Test]
    public function resolveFailsWhenABindingWasNotProduced(): void
    {
        $this->expectException(BindingException::class);
        $this->expectExceptionMessage('$id');

        $this->plan('withArgs')->resolve($this->joinPoint(), []);
    }

    /**
     * @return iterable<string, array{string, mixed, bool}>
     */
    public static function returningTypeCases(): iterable
    {
        yield 'union hit' => ['int|string', 's', true];
        yield 'union miss' => ['int|string', 1.5, false];
        yield 'intersection hit' => [\Countable::class.'&'.\Traversable::class, new \ArrayObject(), true];
        yield 'intersection miss' => [\Countable::class.'&'.\Traversable::class, new \stdClass(), false];
        yield 'class' => [\ArrayObject::class, new \ArrayObject(), true];
        yield 'class miss' => [\ArrayObject::class, 'x', false];
        yield 'mixed' => ['mixed', 1, true];
        yield 'object' => ['object', new \stdClass(), true];
        yield 'object miss' => ['object', 1, false];
        yield 'iterable' => ['iterable', [1], true];
        yield 'iterable miss' => ['iterable', 1, false];
        yield 'callable' => ['callable', 'strlen', true];
        yield 'callable miss' => ['callable', 'no_such_function_aop', false];
        yield 'true' => ['true', true, true];
        yield 'true miss' => ['true', false, false];
        yield 'false' => ['false', false, true];
        yield 'false miss' => ['false', true, false];
        yield 'scalar' => ['int', 1, true];
        yield 'scalar miss' => ['int', '1', false];
    }

    #[Test]
    #[DataProvider('returningTypeCases')]
    public function aDeclaredBindingTypeRestrictsWhatTheAdviceAccepts(string $type, mixed $value, bool $expected): void
    {
        $function = eval('return static function ('.$type.' $bound): void {};');
        self::assertInstanceOf(\Closure::class, $function);
        $reflection = (new \ReflectionFunction($function))->getParameters()[0]->getType();

        $plan = new BindingPlan('advice', [['returning']], $reflection, null);

        self::assertSame($expected, $plan->acceptsReturnValue($value));
    }
}
