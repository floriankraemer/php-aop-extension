<?php

declare(strict_types=1);

namespace AOP\Runtime;

use AOP\Advisor\Advisor;
use AOP\Exception\AspectDefinitionException;
use AOP\Exception\WeaverFrozenException;

/**
 * Advice for one object only (Spring's `Advised` of a proxy). Obtained from `Weaver::advised($object)`; the advisors
 * run in addition to the Weaver's, ordered with them. Not final so the Spring API layer can add convenience methods.
 */
class AdvisedInstance
{
    /** @var array<string, Advisor> */
    private array $advisors = [];

    private bool $frozen = false;

    /** @var array<string, JoinPointPlan> */
    private array $plans = [];

    /**
     * @param \Closure(object): void $changed      called after every change
     * @param \Closure(): bool       $weaverFrozen whether the owning Weaver is frozen
     */
    public function __construct(
        private readonly object $instance,
        private readonly \Closure $changed,
        private readonly \Closure $weaverFrozen,
    ) {
    }

    public function instance(): object
    {
        return $this->instance;
    }

    /**
     * @throws WeaverFrozenException     when frozen
     * @throws AspectDefinitionException when an advisor with this id is already present
     */
    public function addAdvisor(Advisor $advisor): void
    {
        $this->assertMutable();
        if (isset($this->advisors[$advisor->id])) {
            throw new AspectDefinitionException(sprintf('The instance already has an advisor "%s".', $advisor->id));
        }
        $this->advisors[$advisor->id] = $advisor;
        $this->changed();
    }

    /**
     * Removing an unknown id does nothing.
     *
     * @throws WeaverFrozenException when frozen
     */
    public function removeAdvisor(string $id): void
    {
        $this->assertMutable();
        if (isset($this->advisors[$id])) {
            unset($this->advisors[$id]);
            $this->changed();
        }
    }

    /**
     * The advisors of this instance only (not the Weaver's).
     *
     * @return list<Advisor>
     */
    public function advisors(): array
    {
        return array_values($this->advisors);
    }

    public function isFrozen(): bool
    {
        return $this->frozen || ($this->weaverFrozen)();
    }

    public function freeze(): void
    {
        $this->frozen = true;
    }

    /**
     * @internal
     */
    public function plan(string $key): ?JoinPointPlan
    {
        return $this->plans[$key] ?? null;
    }

    /**
     * @internal
     */
    public function storePlan(string $key, JoinPointPlan $plan): void
    {
        $this->plans[$key] = $plan;
    }

    /**
     * @internal
     */
    public function clearPlans(): void
    {
        $this->plans = [];
    }

    private function assertMutable(): void
    {
        if ($this->isFrozen()) {
            throw new WeaverFrozenException('The advice configuration of this instance is frozen.');
        }
    }

    private function changed(): void
    {
        $this->plans = [];
        ($this->changed)($this->instance);
    }
}
