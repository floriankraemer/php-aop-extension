<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Advisor;

use AOP\Advice\AdviceDefinition;
use AOP\Advice\AdviceType;
use AOP\Advisor\Advisor;
use AOP\Advisor\AdvisorChainFactory;
use AOP\Advisor\AdvisorFactory;
use AOP\Advisor\AdvisorRegistry;
use AOP\Advisor\MetadataNamedPointcutResolver;
use AOP\Aspect\ArrayAspectInstanceResolver;
use AOP\Aspect\AspectMetadata;
use AOP\Aspect\AspectMetadataReader;
use AOP\Binding\ParameterBinder;
use AOP\Interceptor\AroundAdviceInterceptor;
use AOP\Interceptor\BeforeAdviceInterceptor;
use AOP\Interceptor\RuntimeGuardInterceptor;
use AOP\Tests\Fixtures\Aspect\NamedPointcutAspect;
use AOP\Tests\Fixtures\Aspect\ProbeAspect;
use AOP\Tests\Fixtures\Aspect\RecordingAspectA;
use AOP\Tests\Fixtures\Aspect\RecordingAspectB;
use AOP\Tests\Fixtures\Jp;
use AOP\Tests\Fixtures\Pointcut\PassThroughInterceptor;
use AOP\Tests\Fixtures\Pointcut\Reflect;
use AOP\Tests\Fixtures\Pointcut\StubPointcut;
use AOP\Tests\Fixtures\Service\Greeter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AdvisorChainFactoryTest extends TestCase
{
    /**
     * @param class-string ...$aspects
     */
    private function factory(string ...$aspects): AdvisorChainFactory
    {
        $reader = new AspectMetadataReader();
        $metas = array_map(static fn (string $c): AspectMetadata => $reader->read($c), $aspects);
        $resolver = new MetadataNamedPointcutResolver($metas);
        $advisors = [];
        foreach ($metas as $meta) {
            array_push($advisors, ...(new AdvisorFactory())->fromMetadata($meta, $resolver));
        }

        return new AdvisorChainFactory(new AdvisorRegistry($advisors), new ArrayAspectInstanceResolver([]), new ParameterBinder());
    }

    /**
     * @param class-string $class
     *
     * @return list<string>
     */
    private function ids(AdvisorChainFactory $f, string $class, string $method): array
    {
        $rc = Reflect::cls($class);

        return array_map(static fn (Advisor $a): string => $a->id, $f->matchedAdvisors($rc, $rc->getMethod($method), Jp::ctx($class, $method)));
    }

    #[Test]
    public function matchedAdvisorsAreInRegistryOrder(): void
    {
        $ids = $this->ids($this->factory(RecordingAspectB::class, RecordingAspectA::class), Greeter::class, 'greet');

        self::assertSame([
            RecordingAspectA::class.'::around',
            RecordingAspectA::class.'::before',
            RecordingAspectA::class.'::after',
            RecordingAspectA::class.'::afterReturning',
            RecordingAspectA::class.'::afterThrowing',
            RecordingAspectB::class.'::around',
            RecordingAspectB::class.'::before',
            RecordingAspectB::class.'::after',
            RecordingAspectB::class.'::afterReturning',
            RecordingAspectB::class.'::afterThrowing',
        ], $ids);
    }

    #[Test]
    public function noMatchGivesEmptyList(): void
    {
        $f = $this->factory(RecordingAspectA::class);
        $rc = Reflect::cls(\ArrayObject::class);

        self::assertSame([], $this->ids($f, \ArrayObject::class, 'count'));
        self::assertSame([], $f->chain($rc, $rc->getMethod('count'), Jp::ctx(\ArrayObject::class, 'count')));
    }

    #[Test]
    public function chainHasOneInterceptorPerAdvisorAndWrapsRuntimePointcuts(): void
    {
        $f = $this->factory(RecordingAspectA::class, NamedPointcutAspect::class);
        $rc = Reflect::cls(Greeter::class);

        $chain = $f->chain($rc, $rc->getMethod('greet'), Jp::ctx(Greeter::class, 'greet'));

        self::assertCount(7, $chain);
        self::assertInstanceOf(AroundAdviceInterceptor::class, $chain[0]);
        self::assertInstanceOf(BeforeAdviceInterceptor::class, $chain[5]);
        self::assertInstanceOf(RuntimeGuardInterceptor::class, $chain[6]);
    }

    #[Test]
    public function staticMatchRequiresClassAndMethod(): void
    {
        $def = new AdviceDefinition(ProbeAspect::class, 'plain', AdviceType::BEFORE, 'x', null, null, 0);
        $f = new AdvisorChainFactory(
            new AdvisorRegistry([
                new Advisor('a', $def, 1, new StubPointcut(class: true, method: false)),
                new Advisor('b', $def, 1, new StubPointcut(class: false, method: true)),
                new Advisor('c', $def, 1, new StubPointcut(class: true, method: true)),
            ]),
            new ArrayAspectInstanceResolver([]),
            new ParameterBinder(),
        );
        $rc = Reflect::cls(Greeter::class);

        $matched = $f->matchedAdvisors($rc, $rc->getMethod('greet'), Jp::ctx(Greeter::class, 'greet'));

        self::assertSame(['c'], array_map(static fn (Advisor $a): string => $a->id, $matched));
    }

    #[Test]
    public function aProgrammaticInterceptorIsUsedAsIs(): void
    {
        $interceptor = new PassThroughInterceptor();
        $f = new AdvisorChainFactory(
            new AdvisorRegistry([new Advisor('prog', null, 1, new StubPointcut(), $interceptor)]),
            new ArrayAspectInstanceResolver([]),
            new ParameterBinder(),
        );
        $rc = Reflect::cls(Greeter::class);

        $chain = $f->chain($rc, $rc->getMethod('greet'), Jp::ctx(Greeter::class, 'greet'));

        self::assertSame([$interceptor], $chain);
    }

    #[Test]
    public function aProgrammaticInterceptorWithARuntimePointcutIsGuarded(): void
    {
        $f = new AdvisorChainFactory(
            new AdvisorRegistry([new Advisor('prog', null, 1, new StubPointcut(runtime: true), new PassThroughInterceptor())]),
            new ArrayAspectInstanceResolver([]),
            new ParameterBinder(),
        );
        $rc = Reflect::cls(Greeter::class);

        $chain = $f->chain($rc, $rc->getMethod('greet'), Jp::ctx(Greeter::class, 'greet'));

        self::assertCount(1, $chain);
        self::assertInstanceOf(RuntimeGuardInterceptor::class, $chain[0]);
    }

    #[Test]
    public function programmaticAndAspectAdvisorsMixInOrder(): void
    {
        $aspects = (new AdvisorFactory());
        $reader = new AspectMetadataReader();
        $meta = $reader->read(RecordingAspectA::class);
        $advisors = $aspects->fromMetadata($meta, new MetadataNamedPointcutResolver([$meta]));
        $first = new PassThroughInterceptor();
        $last = new PassThroughInterceptor();
        $advisors[] = new Advisor('early', null, 0, new StubPointcut(), $first);
        $advisors[] = new Advisor('late', null, 99, new StubPointcut(), $last);
        $f = new AdvisorChainFactory(new AdvisorRegistry($advisors), new ArrayAspectInstanceResolver([]), new ParameterBinder());
        $rc = Reflect::cls(Greeter::class);

        $chain = $f->chain($rc, $rc->getMethod('greet'), Jp::ctx(Greeter::class, 'greet'));

        self::assertCount(7, $chain);
        self::assertSame($first, $chain[0]);
        self::assertSame($last, $chain[6]);
    }
}
