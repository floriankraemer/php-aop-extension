<?php

declare(strict_types=1);

namespace AOP\Pointcut;

/**
 * Full (static + runtime) evaluation of a pointcut for combinators that cannot rely on weave-time pruning.
 *
 * @internal
 */
final class RuntimeEvaluation
{
    public static function matches(PointcutInterface $pointcut, RuntimeMatchContext $ctx): bool
    {
        $static = $ctx->staticContext;

        return $pointcut->matchesClass($static->targetClass, $static)
            && $pointcut->matchesMethod($static->function, $static)
            && (!$pointcut->isRuntime() || $pointcut->matchesRuntime($ctx));
    }
}
