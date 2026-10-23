<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Advisor;

use AOP\Advice\AdviceType;
use AOP\Advisor\AdvisorRegistry;
use AOP\Advisor\IntroductionAdvisor;
use AOP\Exception\AspectDefinitionException;
use AOP\Pointcut\Pattern\TypePattern;
use AOP\Tests\Fixtures\Introduction\AlreadyAuditableWorkTarget;
use AOP\Tests\Fixtures\Introduction\Auditable;
use AOP\Tests\Fixtures\Introduction\AuditTrail;
use AOP\Tests\Fixtures\Introduction\BareTarget;
use AOP\Tests\Fixtures\Introduction\ClashingPrivateTarget;
use AOP\Tests\Fixtures\Introduction\ClashingPublicTarget;
use AOP\Tests\Fixtures\Introduction\WorkTarget;
use AOP\Tests\Fixtures\Pointcut\Reflect;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AdvisorRegistryTest extends TestCase
{
    public function testSortsFiltersAndCounts(): void
    {
        $a = AdvisorOrderComparatorTest::advisor(...);
        $r = new AdvisorRegistry([
            $a('B', 'm', AdviceType::BEFORE, 2, 0),
            $a('A', 'z', AdviceType::AFTER, 1, 1),
            $a('A', 'y', AdviceType::AROUND, 1, 0),
        ]);

        self::assertSame(3, $r->count());
        self::assertSame(['A::y', 'A::z', 'B::m'], array_map(static fn ($x) => $x->id, $r->all()));
        self::assertSame(['A::y', 'A::z'], array_map(static fn ($x) => $x->id, $r->forAspect('A')));
        self::assertSame([], $r->forAspect('Nope'));
    }

    public function testProgrammaticAdvisorsAreSortedButBelongToNoAspect(): void
    {
        $a = AdvisorOrderComparatorTest::advisor(...);
        $programmatic = new \AOP\Advisor\Advisor('prog', null, 0, new \AOP\Tests\Fixtures\Pointcut\StubPointcut(), new \AOP\Tests\Fixtures\Pointcut\PassThroughInterceptor());
        $r = new AdvisorRegistry([$a('A', 'm', AdviceType::BEFORE, 1, 0), $programmatic]);

        self::assertSame(['prog', 'A::m'], array_map(static fn ($x) => $x->id, $r->all()));
        self::assertSame(['A::m'], array_map(static fn ($x) => $x->id, $r->forAspect('A')));
        self::assertSame([], $r->forAspect('prog'));
    }

    /**
     * @param class-string $interface
     */
    private static function introduction(string $aspect, string $pattern, int $order = 0, string $interface = Auditable::class): IntroductionAdvisor
    {
        return new IntroductionAdvisor($aspect, new TypePattern($pattern), $interface, AuditTrail::class, $order);
    }

    public function testIntroductionsAreOrderedAndMatchedAgainstTheTargetType(): void
    {
        $r = new AdvisorRegistry([], [
            self::introduction('B', 'Nope\**', 2),
            self::introduction('A', 'AOP\Tests\Fixtures\Introduction\*WorkTarget', 1),
        ]);

        self::assertSame(['A', 'B'], array_map(static fn ($i) => $i->aspectClass, $r->introductions()));
        self::assertSame([Auditable::class], array_keys($r->introductionsFor(Reflect::cls(WorkTarget::class))));
        self::assertSame('A', $r->introductionsFor(Reflect::cls(WorkTarget::class))[Auditable::class]->aspectClass);
        self::assertSame([], $r->introductionsFor(Reflect::cls(BareTarget::class)));
        self::assertSame([], $r->introductionsFor(Reflect::cls(AlreadyAuditableWorkTarget::class)), 'an interface the target implements is not introduced again');
    }

    public function testTwoIntroductionsOfTheSameInterfaceForOneTargetAreRejected(): void
    {
        $r = new AdvisorRegistry([], [
            self::introduction('A', '**\WorkTarget'),
            self::introduction('B', 'AOP\Tests\Fixtures\Introduction\*'),
        ]);

        $this->expectException(AspectDefinitionException::class);
        $this->expectExceptionMessage('"A" and "B" both introduce interface "'.Auditable::class.'"');
        $r->introductionsFor(Reflect::cls(WorkTarget::class));
    }

    /**
     * @param class-string $target
     */
    #[DataProvider('clashingTargets')]
    public function testAnIntroducedMethodNameTheTargetAlreadyDeclaresIsRejected(string $target): void
    {
        $r = new AdvisorRegistry([], [self::introduction('A', 'AOP\Tests\Fixtures\Introduction\Clashing*')]);

        $this->expectException(AspectDefinitionException::class);
        $this->expectExceptionMessage(sprintf('Aspect "A" introduces interface "%s" into class "%s", which already declares method "recordAudit()"', Auditable::class, $target));
        $r->introductionsFor(Reflect::cls($target));
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function clashingTargets(): iterable
    {
        yield 'public, different signature' => [ClashingPublicTarget::class];
        yield 'private' => [ClashingPrivateTarget::class];
    }
}
