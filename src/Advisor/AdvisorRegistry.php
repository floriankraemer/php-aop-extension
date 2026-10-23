<?php

declare(strict_types=1);

namespace AOP\Advisor;

use AOP\Exception\AspectDefinitionException;

final class AdvisorRegistry implements \Countable
{
    /** @var list<Advisor> */
    private array $advisors;

    /** @var list<IntroductionAdvisor> */
    private array $introductions;

    /**
     * @param list<Advisor>             $advisors
     * @param list<IntroductionAdvisor> $introductions
     */
    public function __construct(array $advisors, array $introductions = [])
    {
        usort($advisors, new AdvisorOrderComparator());
        usort($introductions, static fn (IntroductionAdvisor $a, IntroductionAdvisor $b): int => $a->order <=> $b->order);
        $this->advisors = $advisors;
        $this->introductions = $introductions;
    }

    /**
     * @return list<IntroductionAdvisor> by aspect order
     */
    public function introductions(): array
    {
        return $this->introductions;
    }

    /**
     * Introductions applying to the target class, keyed by interface.
     *
     * @param \ReflectionClass<object> $target
     *
     * @return array<class-string, IntroductionAdvisor>
     *
     * @throws AspectDefinitionException when two introductions of the same interface apply, or the target declares
     *                                   a method of the introduced interface without implementing it
     */
    public function introductionsFor(\ReflectionClass $target): array
    {
        $matched = [];
        foreach ($this->introductions as $introduction) {
            if (!$introduction->appliesTo($target)) {
                continue;
            }
            self::assertNoMethodClash($introduction, $target);
            $other = $matched[$introduction->interface] ?? null;
            if (null !== $other) {
                throw new AspectDefinitionException(sprintf('Aspects "%s" and "%s" both introduce interface "%s" into class "%s"; narrow one of the type patterns.', $other->aspectClass, $introduction->aspectClass, $introduction->interface, $target->getName()));
            }
            $matched[$introduction->interface] = $introduction;
        }

        return $matched;
    }

    /**
     * The class cannot receive an interface method it already declares under another contract:
     * a public one with a different signature is a fatal error at load, a non-public one would shadow the introduction.
     *
     * @param \ReflectionClass<object> $target
     */
    private static function assertNoMethodClash(IntroductionAdvisor $introduction, \ReflectionClass $target): void
    {
        foreach ((new \ReflectionClass($introduction->interface))->getMethods() as $method) {
            if ($target->hasMethod($method->getName())) {
                throw new AspectDefinitionException(sprintf('Aspect "%s" introduces interface "%s" into class "%s", which already declares method "%s()" without implementing the interface; rename the method, implement the interface, or narrow the type pattern.', $introduction->aspectClass, $introduction->interface, $target->getName(), $target->getMethod($method->getName())->getName()));
            }
        }
    }

    /**
     * @return list<Advisor>
     */
    public function all(): array
    {
        return $this->advisors;
    }

    /**
     * @return list<Advisor>
     */
    public function forAspect(string $class): array
    {
        return array_values(array_filter($this->advisors, static fn (Advisor $a): bool => $a->advice?->aspectClass === $class));
    }

    public function count(): int
    {
        return count($this->advisors);
    }
}
