<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Runtime;

use AOP\Advice\AdviceDefinition;
use AOP\Advice\AdviceType;
use AOP\Advisor\Advisor;
use AOP\Exception\AspectDefinitionException;
use AOP\JoinPoint\JoinPointKind;
use AOP\Runtime\AdvisorOrdering;
use AOP\Tests\Fixtures\Hierarchy\AbstractRepository;
use AOP\Tests\Fixtures\Hierarchy\Plain;
use AOP\Tests\Fixtures\Hierarchy\UserRepository;
use AOP\Tests\Fixtures\Log;
use AOP\Tests\Fixtures\Runtime\Aspect\OrderedByInterfaceAspect;
use AOP\Tests\Fixtures\Runtime\Aspect\PrecA;
use AOP\Tests\Fixtures\Runtime\Aspect\PrecB;
use AOP\Tests\Fixtures\Runtime\Aspect\PrecC;
use AOP\Tests\Fixtures\Runtime\KindPointcut;
use AOP\Tests\Fixtures\Runtime\TargetService;
use AOP\Tests\Fixtures\Runtime\Trace;
use AOP\Weaver;
use PHPUnit\Framework\Attributes\Test;

final class OrderingTest extends RuntimeTestCase
{
    /**
     * @return list<string>
     */
    private function weaveAndCall(Weaver $weaver): array
    {
        $weaver->enable();
        $this->engine->call(new TargetService(), 'pub', ['x']);

        return array_values(array_diff(Log::$entries, ['pub']));
    }

    #[Test]
    public function orderAttributesDecideWithoutDeclarations(): void
    {
        self::assertSame(['PrecA', 'PrecC'], $this->weaveAndCall(Weaver::fromAspects([new PrecC(), new PrecA()], $this->engine)));
    }

    #[Test]
    public function declarePrecedenceOverridesOrderAndStarMeansEverythingElse(): void
    {
        // PrecB declares "PrecC, *, PrecA": C first, A last, B (the star) between, although #[Order] says A, B, C.
        self::assertSame(['PrecC', 'PrecB', 'PrecA'], $this->weaveAndCall(Weaver::fromAspects([new PrecA(), new PrecB(), new PrecC()], $this->engine)));
    }

    #[Test]
    public function declarationsOnlyRelateTheAspectsTheyName(): void
    {
        $sorted = AdvisorOrdering::sort($this->advisors(['X' => 1, 'Y' => 2, 'Z' => 3]), ['Z, X']);

        self::assertSame(['Y', 'Z', 'X'], array_map(static fn (Advisor $a): string => $a->id, $sorted));
    }

    #[Test]
    public function contradictingDeclarationsAreRejected(): void
    {
        $this->expectException(AspectDefinitionException::class);
        AdvisorOrdering::sort($this->advisors(['X' => 1, 'Y' => 2]), ['X, Y', 'Y, X']);
    }

    #[Test]
    public function theOrderedInterfaceOverridesTheOrderAttribute(): void
    {
        $result = $this->weaveAndCall(Weaver::fromAspects([new PrecA(), new OrderedByInterfaceAspect(0), new PrecC()], $this->engine));

        self::assertSame(['ordered:0', 'PrecA', 'PrecC'], $result);
    }

    #[Test]
    public function theOrderedInterfaceCanAlsoPlaceAnAspectLast(): void
    {
        $result = $this->weaveAndCall(Weaver::fromAspects([new PrecA(), new OrderedByInterfaceAspect(50), new PrecC()], $this->engine));

        self::assertSame(['PrecA', 'PrecC', 'ordered:50'], $result);
    }

    #[Test]
    public function programmaticAdvisorsInterleaveByOrder(): void
    {
        $weaver = Weaver::fromAspects([new PrecA(), new PrecC()], $this->engine);
        $weaver->addAdvisor(new Advisor('mid', null, 2, new KindPointcut([JoinPointKind::METHOD_EXECUTION], 'pub'), new Trace('mid')));

        self::assertSame(['PrecA', 'mid>', 'PrecC', 'mid<'], $this->weaveAndCall($weaver));
    }

    /**
     * @param array<string, int> $orders id => order
     *
     * @return list<Advisor>
     */
    private function advisors(array $orders): array
    {
        $advisors = [];
        foreach ($orders as $id => $order) {
            $advisors[] = new Advisor($id, null, $order, new KindPointcut([]), new Trace($id));
        }

        return $advisors;
    }

    #[Test]
    public function aDeclarationWithTwoStarsIsRejected(): void
    {
        $this->expectException(AspectDefinitionException::class);
        $this->expectExceptionMessage('contains "*" twice');

        AdvisorOrdering::sort($this->advisors(['X' => 1, 'Y' => 2]), ['X, *, *']);
    }

    /**
     * @param class-string $aspect
     */
    private static function adviceOf(string $aspect, string $method, int $order): Advisor
    {
        return new Advisor($aspect.'::'.$method, new AdviceDefinition($aspect, $method, AdviceType::BEFORE, 'x', null, null, 0), $order, new KindPointcut([]));
    }

    #[Test]
    public function anAspectIsPlacedByItsLowestOrderAndItsOwnAdvisorsStaySortedWithinIt(): void
    {
        $sorted = AdvisorOrdering::sort([self::adviceOf(PrecA::class, 'late', 5), self::adviceOf(PrecA::class, 'early', 1), self::adviceOf(PrecB::class, 'only', 3), self::adviceOf(PrecA::class, 'last', 6)]);

        self::assertSame([PrecA::class.'::early', PrecA::class.'::late', PrecA::class.'::last', PrecB::class.'::only'], array_map(static fn (Advisor $a): string => $a->id, $sorted));
    }

    #[Test]
    public function aPrecedenceElementWithAPlusAlsoNamesSubtypes(): void
    {
        $sorted = AdvisorOrdering::sort(
            $this->advisors([Plain::class => 1, UserRepository::class => 2]),
            [AbstractRepository::class.'+, *'],
        );

        self::assertSame([UserRepository::class, Plain::class], array_map(static fn (Advisor $a): string => $a->id, $sorted));
    }

    #[Test]
    public function anAspectMatchingSeveralElementsTakesTheFirstOne(): void
    {
        // ac matches "ac" (position 0) and "a*" (position 1): it ranks 0 and so precedes ab, which only matches "a*".
        $sorted = AdvisorOrdering::sort($this->advisors(['ab' => 1, 'ac' => 2]), ['ac, a*']);

        self::assertSame(['ac', 'ab'], array_map(static fn (Advisor $a): string => $a->id, $sorted));
    }
}
