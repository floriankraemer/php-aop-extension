<?php

declare(strict_types=1);

namespace AOP\Runtime;

use AOP\Advisor\Advisor;
use AOP\Advisor\AdvisorOrderComparator;
use AOP\Exception\AspectDefinitionException;
use AOP\Pointcut\Pattern\TypePattern;

/**
 * Sorts advisors outermost first. The advisors of one source (an aspect class, or a programmatic advisor id) run as a block;
 * blocks are ordered by their order value, then source name (like AdvisorOrderComparator), and then adjusted to honour
 * `declare precedence` lists. Inside a block AdvisorOrderComparator applies.
 */
final class AdvisorOrdering
{
    private function __construct()
    {
    }

    /**
     * @param list<Advisor> $advisors
     * @param list<string>  $declarations `declare precedence` lists such as 'A, B, *'
     *
     * @return list<Advisor>
     *
     * @throws AspectDefinitionException on contradicting precedence declarations
     */
    public static function sort(array $advisors, array $declarations = []): array
    {
        /** @var array<string, list<Advisor>> $groups */
        $groups = [];
        $minOrder = [];
        foreach ($advisors as $advisor) {
            $source = $advisor->source();
            $groups[$source][] = $advisor;
            $minOrder[$source] = min($minOrder[$source] ?? $advisor->order, $advisor->order);
        }

        $sources = array_map('strval', array_keys($groups));
        usort($sources, static fn (string $a, string $b): int => [$minOrder[$a], $a] <=> [$minOrder[$b], $b]);
        if ([] !== $declarations) {
            $sources = self::applyPrecedence($sources, $declarations);
        }

        $sorted = [];
        foreach ($sources as $source) {
            $group = $groups[$source];
            usort($group, new AdvisorOrderComparator());
            array_push($sorted, ...$group);
        }

        return $sorted;
    }

    /**
     * Stable topological sort of the sources: keeps the default sequence wherever no declaration says otherwise.
     *
     * @param list<string> $sources      default sequence
     * @param list<string> $declarations
     *
     * @return list<string>
     */
    private static function applyPrecedence(array $sources, array $declarations): array
    {
        /** @var array<string, array<string, true>> $after sources that must come after the key */
        $after = [];
        foreach ($declarations as $declaration) {
            $ranks = self::ranks($declaration, $sources);
            foreach ($ranks as $first => $rankFirst) {
                foreach ($ranks as $second => $rankSecond) {
                    if ($rankFirst < $rankSecond) {
                        $after[(string) $first][(string) $second] = true;
                    }
                }
            }
        }

        $blockers = array_fill_keys($sources, 0);
        foreach ($after as $followers) {
            foreach (array_keys($followers) as $follower) {
                ++$blockers[$follower];
            }
        }

        $result = [];
        $remaining = $sources;
        while ([] !== $remaining) {
            $next = null;
            foreach ($remaining as $index => $source) {
                if (0 === $blockers[$source]) {
                    $next = $index;
                    break;
                }
            }
            if (null === $next) {
                throw new AspectDefinitionException(sprintf('Contradicting #[DeclarePrecedence] declarations between %s.', implode(', ', array_values($remaining))));
            }
            $source = $remaining[$next];
            unset($remaining[$next]);
            $result[] = $source;
            foreach (array_keys($after[$source] ?? []) as $follower) {
                --$blockers[$follower];
            }
        }

        return $result;
    }

    /**
     * Position of each aspect in one declaration (the first element matching it; `*` for those matching none).
     *
     * @param list<string> $aspects
     *
     * @return array<string, int>
     */
    private static function ranks(string $declaration, array $aspects): array
    {
        $elements = array_values(array_filter(array_map('trim', explode(',', $declaration)), static fn (string $e): bool => '' !== $e));
        $star = null;
        $patterns = [];
        foreach ($elements as $position => $element) {
            if ('*' === $element) {
                if (null !== $star) {
                    throw new AspectDefinitionException(sprintf('#[DeclarePrecedence] "%s" contains "*" twice.', $declaration));
                }
                $star = $position;
                continue;
            }
            $patterns[$position] = new TypePattern(rtrim($element, '+'), false, str_ends_with($element, '+'));
        }

        $ranks = [];
        foreach ($aspects as $aspect) {
            $rank = $star;
            foreach ($patterns as $position => $pattern) {
                if ($pattern->matchesName($aspect)) {
                    $rank = $position;
                    break;
                }
            }
            if (null !== $rank) {
                $ranks[$aspect] = $rank;
            }
        }

        return $ranks;
    }
}
