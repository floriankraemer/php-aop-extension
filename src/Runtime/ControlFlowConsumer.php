<?php

declare(strict_types=1);

namespace AOP\Runtime;

use AOP\Pointcut\PointcutInterface;

/**
 * Implemented by pointcuts that read the control flow stack (`cflow()`, `cflowbelow()`). The runtime asks the
 * engine to dispatch every join point matching the returned pointcut (answer `Engine::TRACK`), so that it is
 * on the stack even when no advice applies to it.
 */
interface ControlFlowConsumer
{
    /**
     * The pointcut whose join points must appear on the control flow stack.
     */
    public function controlFlowPointcut(): PointcutInterface;
}
