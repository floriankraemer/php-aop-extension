<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Runtime;

use AOP\Advisor\Advisor;
use AOP\Advisor\Api\DefaultPointcutAdvisor;
use AOP\Exception\AspectDefinitionException;
use AOP\JoinPoint\JoinPointKind;
use AOP\Pointcut\Api\ApiPointcut;
use AOP\Pointcut\Api\ComposablePointcut;
use AOP\Pointcut\Api\ExpressionPointcut;
use AOP\Pointcut\Api\NameMatchMethodPointcut;
use AOP\Pointcut\Api\TrueClassFilter;
use AOP\Tests\Fixtures\Runtime\KindPointcut;
use AOP\Tests\Fixtures\Runtime\Trace;
use AOP\Weaver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Without field interception (aop.interception=woven) get/set/initialization advice would silently never run,
 * so Weaver::enable() refuses it.
 */
final class WeaverFieldInterceptionTest extends RuntimeTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function fieldPointcuts(): iterable
    {
        yield 'get' => ['get(* App\Foo::$bar)'];
        yield 'set' => ['set(* App\Foo::$bar)'];
        yield 'initialization' => ['initialization(App\Foo::__construct(..))'];
        yield 'initialization of subtypes' => ['initialization(App\Foo+)'];
        yield 'or with execution' => ['execution(* App\Foo::run(..)) || get(* App\Foo::$bar)'];
        yield 'and' => ['get(* App\Foo::$bar) && within(App\**)'];
        yield 'nested or' => ['execution(* App\Foo::run(..)) || (within(App\Other) && set(* App\Foo::$bar))'];
        yield 'cflow of a field' => ['execution(* App\Foo::run(..)) && cflow(get(* App\Foo::$bar))'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function otherPointcuts(): iterable
    {
        yield 'execution' => ['execution(* App\Foo::run(..))'];
        yield 'call' => ['call(* App\Foo::run(..))'];
        yield 'handler' => ['handler(\Exception)'];
        yield 'or of executions' => ['execution(* App\Foo::run(..)) || execution(* App\Bar::*(..))'];
        yield 'negated field' => ['execution(* App\Foo::*(..)) && !get(* App\Foo::$bar)'];
    }

    #[Test]
    #[DataProvider('fieldPointcuts')]
    public function enableThrowsForFieldPointcutsWithoutFieldInterception(string $expression): void
    {
        $weaver = $this->weaver(new Advisor('field.advisor', null, 1, new ExpressionPointcut($expression), new Trace('t')), false);

        $this->expectException(AspectDefinitionException::class);
        $this->expectExceptionMessage('Advisor "field.advisor"');
        $this->expectExceptionMessage('aop.interception=hook');
        try {
            $weaver->enable();
        } finally {
            self::assertFalse($weaver->isEnabled());
            self::assertFalse($this->engine->isRegistered());
        }
    }

    #[Test]
    #[DataProvider('fieldPointcuts')]
    public function enableAcceptsFieldPointcutsWithFieldInterception(string $expression): void
    {
        $weaver = $this->weaver(new Advisor('field.advisor', null, 1, new ExpressionPointcut($expression), new Trace('t')), true);

        $weaver->enable();

        self::assertTrue($weaver->isEnabled());
    }

    #[Test]
    #[DataProvider('otherPointcuts')]
    public function enableAcceptsOtherPointcutsWithoutFieldInterception(string $expression): void
    {
        $weaver = $this->weaver(new Advisor('plain', null, 1, new ExpressionPointcut($expression), new Trace('t')), false);

        $weaver->enable();

        self::assertTrue($weaver->isEnabled());
    }

    #[Test]
    public function compiledExpressionsOfAspectsAreChecked(): void
    {
        $weaver = $this->weaver(new Advisor('ok', null, 1, new KindPointcut([JoinPointKind::METHOD_EXECUTION]), new Trace('t')), false);
        $weaver->addAdvisor((new DefaultPointcutAdvisor('get(* App\Foo::$bar)', new Trace('t'), id: 'spring.field'))->toAdvisor());

        $this->expectException(AspectDefinitionException::class);
        $this->expectExceptionMessage('Advisor "spring.field"');
        $weaver->enable();
    }

    #[Test]
    public function springPointcutsOnExecutionKindsPass(): void
    {
        $weaver = $this->weaver(new Advisor('name', null, 1, new ApiPointcut(TrueClassFilter::INSTANCE, (new NameMatchMethodPointcut('get*'))->getMethodMatcher()), new Trace('t')), false);
        $weaver->addAdvisor((new DefaultPointcutAdvisor(new ComposablePointcut(new ExpressionPointcut('execution(* App\Foo::run(..))')), new Trace('t'), id: 'composed'))->toAdvisor());

        $weaver->enable();

        self::assertTrue($weaver->isEnabled());
    }

    #[Test]
    public function springPointcutsOnFieldKindsAreRejected(): void
    {
        $fieldKinds = new ApiPointcut(TrueClassFilter::INSTANCE, (new NameMatchMethodPointcut('*'))->getMethodMatcher(), [JoinPointKind::FIELD_SET]);
        $weaver = $this->weaver(new Advisor('spring.kinds', null, 1, $fieldKinds, new Trace('t')), false);

        $this->expectException(AspectDefinitionException::class);
        $this->expectExceptionMessage('Advisor "spring.kinds"');
        $weaver->enable();
    }

    #[Test]
    public function composedSpringPointcutsAreRejectedWhenAnyPartNeedsFields(): void
    {
        $composed = (new ComposablePointcut(new ExpressionPointcut('execution(* App\Foo::run(..))')))->union(new ExpressionPointcut('initialization(App\Foo+)'));
        $weaver = $this->weaver(new Advisor('composed', null, 1, $composed, new Trace('t')), false);

        $this->expectException(AspectDefinitionException::class);
        $this->expectExceptionMessage('Advisor "composed"');
        $weaver->enable();
    }

    #[Test]
    public function enableIsNotCheckedWhenAlreadyEnabled(): void
    {
        $weaver = $this->weaver(new Advisor('plain', null, 1, new ExpressionPointcut('execution(* App\Foo::run(..))'), new Trace('t')), false);
        $weaver->enable();

        $weaver->enable();

        self::assertTrue($weaver->isEnabled());
    }

    private function weaver(Advisor $advisor, bool $fieldInterception): Weaver
    {
        $this->engine->fieldInterception = $fieldInterception;
        $weaver = new Weaver($this->engine);
        $weaver->addAdvisor($advisor);

        return $weaver;
    }
}
