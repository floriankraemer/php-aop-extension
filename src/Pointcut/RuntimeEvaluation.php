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
        // Without a weave-time context, service() and interface-strategy information is unavailable; the weaver passes the real one.
        $static = $ctx->staticContext ?? new StaticMatchContext($ctx->targetClass());

        return $pointcut->matchesClass($static->targetClass, $static)
            && $pointcut->matchesMethod($ctx->method, $static)
            && (!$pointcut->isRuntime() || $pointcut->matchesRuntime($ctx));
    }
}
