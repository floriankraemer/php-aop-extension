<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Advisor;

use AOP\Advice\AdviceDefinition;
use AOP\Advice\AdviceType;
use AOP\Advisor\Advisor;
use AOP\Exception\AspectDefinitionException;
use AOP\Tests\Fixtures\Aspect\ProbeAspect;
use AOP\Tests\Fixtures\Pointcut\PassThroughInterceptor;
use AOP\Tests\Fixtures\Pointcut\StubPointcut;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AdvisorTest extends TestCase
{
    private function definition(AdviceType $type = AdviceType::BEFORE): AdviceDefinition
    {
        return new AdviceDefinition(ProbeAspect::class, 'plain', $type, 'x()', null, null, 0);
    }

    #[Test]
    public function anAspectAdvisorUsesItsAspectClassAsSourceAndItsAdviceType(): void
    {
        $advisor = new Advisor('id', $this->definition(AdviceType::AFTER), 1, new StubPointcut());

        self::assertSame(ProbeAspect::class, $advisor->source());
        self::assertSame(AdviceType::AFTER, $advisor->adviceType());
        self::assertNull($advisor->interceptor);
    }

    #[Test]
    public function aProgrammaticAdvisorUsesItsIdAndDefaultsToAround(): void
    {
        $interceptor = new PassThroughInterceptor();
        $advisor = new Advisor('my-id', null, 1, new StubPointcut(), $interceptor);

        self::assertSame('my-id', $advisor->source());
        self::assertSame(AdviceType::AROUND, $advisor->adviceType());
        self::assertSame($interceptor, $advisor->interceptor);
        self::assertSame(AdviceType::BEFORE, (new Advisor('my-id', null, 1, new StubPointcut(), $interceptor, AdviceType::BEFORE))->adviceType());
    }

    #[Test]
    public function exactlyOneOfAdviceAndInterceptorIsRequired(): void
    {
        $this->expectException(AspectDefinitionException::class);

        new Advisor('none', null, 1, new StubPointcut());
    }

    #[Test]
    public function bothAdviceAndInterceptorAreRejected(): void
    {
        $this->expectException(AspectDefinitionException::class);

        new Advisor('both', $this->definition(), 1, new StubPointcut(), new PassThroughInterceptor());
    }
}
