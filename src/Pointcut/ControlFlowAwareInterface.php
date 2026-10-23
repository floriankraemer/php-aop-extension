<?php

declare(strict_types=1);

namespace AOP\Pointcut;

/**
 * Implemented by pointcuts that read the control flow (cflow, cflowbelow) and by the combinators containing them.
 * The runtime must TRACK every join point that statically matches one of the returned pointcuts, so that it appears
 * on ControlFlowInterface::stack() while it is active, even when no advice applies to it.
 */
interface ControlFlowAwareInterface
{
    /**
     * The inner pointcuts of every cflow()/cflowbelow() in this pointcut, nested ones included.
     *
     * @return list<PointcutInterface>
     */
    public function cflowPointcuts(): array;
}
