<?php

declare(strict_types=1);

namespace AOP\Pointcut;

/**
 * Entry point for the runtime: which pointcuts of a pointcut tree select the join points to TRACK for cflow().
 */
final class ControlFlowPointcuts
{
    private function __construct()
    {
    }

    /**
     * The inner pointcuts of all cflow()/cflowbelow() designators in the tree, empty when there is none (also for
     * pointcut implementations that know nothing about control flow).
     *
     * A join point must be tracked when `matchesClass($class, $ctx) && matchesMethod($function, $ctx)` holds for one of
     * them: that is the static half of the inner pointcut; its runtime half is evaluated when the cflow is consulted.
     *
     * @return list<PointcutInterface>
     */
    public static function of(PointcutInterface $pointcut): array
    {
        return $pointcut instanceof ControlFlowAwareInterface ? $pointcut->cflowPointcuts() : [];
    }
}
