<?php

declare(strict_types=1);

namespace AOP\Runtime;

use AOP\JoinPoint\JoinPointInterface;

/**
 * Control flow bookkeeping of one Fiber (or of the main execution).
 *
 * @internal
 */
final class FlowState
{
    /** @var list<JoinPointInterface> */
    public array $frames = [];

    /** Number of frames that count for percflowbelow (all but advice-execution frames). */
    public int $depth = 0;

    /** @var array<class-string, list<FlowToken>> active percflow tokens per aspect class, innermost last */
    public array $tokens = [];

    /** @var array<string, true> advice methods currently wrapped in an advice-execution join point */
    public array $wrapping = [];
}
