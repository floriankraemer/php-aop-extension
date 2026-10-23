<?php

declare(strict_types=1);

namespace AOP\Pointcut;

use AOP\JoinPoint\JoinPointInterface;

interface ControlFlowInterface
{
    /**
     * Active join points of the current Fiber, outermost first, the current one last.
     *
     * @return list<JoinPointInterface>
     */
    public function stack(): array;
}
