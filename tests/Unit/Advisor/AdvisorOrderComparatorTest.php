<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Advisor;

use AOP\Advice\AdviceDefinition;
use AOP\Advice\AdviceType;
use AOP\Advisor\Advisor;
use AOP\Advisor\AdvisorOrderComparator;
use AOP\Tests\Fixtures\Pointcut\PassThroughInterceptor;
use AOP\Tests\Fixtures\Pointcut\StubPointcut;
use PHPUnit\Framework\TestCase;

final class AdvisorOrderComparatorTest extends TestCase
{
    public static function advisor(string $aspect, string $method, AdviceType $type, int $order, int $index): Advisor
    {
        return new Advisor(
            $aspect.'::'.$method,
            new AdviceDefinition($aspect, // @phpstan-ignore argument.type
                $method, $type, 'x()', null, null, $index),
            $order,
            new StubPointcut(),
        );
    }

    /**
     * @param list<Advisor> $advisors
     *
     * @return list<string>
     */
    private function sorted(array $advisors): array
    {
        usort($advisors, new AdvisorOrderComparator());

        return array_map(static fn (Advisor $a) => $a->id, $advisors);
    }

    public function testAspectOrderThenTypeThenDeclaration(): void
    {
        $a = self::advisor(...);
        $list = [
            $a('B', 'afterThrowing', AdviceType::AFTER_THROWING, 2, 0),
            $a('A', 'afterReturning', AdviceType::AFTER_RETURNING, 1, 0),
            $a('B', 'around', AdviceType::AROUND, 2, 1),
            $a('A', 'after', AdviceType::AFTER, 1, 1),
            $a('A', 'before2', AdviceType::BEFORE, 1, 3),
            $a('A', 'before1', AdviceType::BEFORE, 1, 2),
            $a('A', 'afterThrowing', AdviceType::AFTER_THROWING, 1, 4),
            $a('A', 'around', AdviceType::AROUND, 1, 5),
            $a('B', 'before', AdviceType::BEFORE, 2, 2),
        ];

        self::assertSame(
            ['A::around', 'A::before1', 'A::before2', 'A::after', 'A::afterReturning', 'A::afterThrowing', 'B::around', 'B::before', 'B::afterThrowing'],
            $this->sorted($list),
        );
    }

    public function testClassNameIsFinalTiebreak(): void
    {
        $a = self::advisor(...);

        self::assertSame(
            ['A::m', 'B::m'],
            $this->sorted([$a('B', 'm', AdviceType::BEFORE, 5, 0), $a('A', 'm', AdviceType::BEFORE, 5, 0)]),
        );
    }

    public function testAspectsWithEqualOrderRunAsBlocks(): void
    {
        $a = self::advisor(...);
        $list = [
            $a('Zeta', 'a', AdviceType::BEFORE, PHP_INT_MAX, 0),
            $a('Alpha', 'a', AdviceType::BEFORE, PHP_INT_MAX, 0),
            $a('Zeta', 'b', AdviceType::BEFORE, PHP_INT_MAX, 1),
            $a('Alpha', 'around', AdviceType::AROUND, PHP_INT_MAX, 2),
            $a('Alpha', 'b', AdviceType::BEFORE, PHP_INT_MAX, 1),
        ];

        self::assertSame(
            ['Alpha::around', 'Alpha::a', 'Alpha::b', 'Zeta::a', 'Zeta::b'],
            $this->sorted($list),
        );
    }

    public function testEqualAdvisorsCompareZero(): void
    {
        $a = self::advisor('A', 'm', AdviceType::BEFORE, 5, 0);

        self::assertSame(0, (new AdvisorOrderComparator())($a, $a));
    }

    public function testProgrammaticAdvisorsSortByOrderThenIdThenTypeDefaultingToAround(): void
    {
        $prog = static fn (string $id, int $order, ?AdviceType $type = null): Advisor => new Advisor($id, null, $order, new StubPointcut(), new PassThroughInterceptor(), $type);
        $aspect = self::advisor('M', 'before', AdviceType::BEFORE, 5, 0);

        self::assertSame(
            ['early', 'A', 'M::before', 'Z'],
            $this->sorted([$prog('Z', 5), $aspect, $prog('A', 5), $prog('early', 1)]),
        );
        self::assertSame(
            ['X-after', 'X-around', 'X-before'],
            $this->sorted([$prog('X-after', 5, AdviceType::AFTER), $prog('X-before', 5, AdviceType::BEFORE), $prog('X-around', 5)]),
            'ids differ so the id decides before the type',
        );
    }

    public function testProgrammaticAdvisorsOfOneSourceUseTheType(): void
    {
        $same = static fn (string $id, ?AdviceType $type): Advisor => new Advisor('same', null, 5, new StubPointcut(), new PassThroughInterceptor(), $type);
        $around = $same('x', null);
        $before = $same('y', AdviceType::BEFORE);
        $after = $same('z', AdviceType::AFTER);

        $list = [$after, $before, $around];
        usort($list, new AdvisorOrderComparator());

        self::assertSame([$around, $before, $after], $list);
    }

    public function testAspectAdvisorAndProgrammaticAdvisorWithTheSameSourceUseTypePrecedence(): void
    {
        $aspect = self::advisor('S', 'before', AdviceType::BEFORE, 5, 0);
        $prog = new Advisor('S', null, 5, new StubPointcut(), new PassThroughInterceptor());

        self::assertSame([$prog, $aspect], $this->sortedAdvisors([$aspect, $prog]));
    }

    /**
     * @param list<Advisor> $advisors
     *
     * @return list<Advisor>
     */
    private function sortedAdvisors(array $advisors): array
    {
        usort($advisors, new AdvisorOrderComparator());

        return $advisors;
    }
}
