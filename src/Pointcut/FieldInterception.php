<?php

declare(strict_types=1);

namespace AOP\Pointcut;

use AOP\JoinPoint\JoinPointKind;

/**
 * Entry point for the Weaver: does a pointcut tree select join points that need field interception?
 */
final class FieldInterception
{
    private function __construct()
    {
    }

    public static function requiredBy(PointcutInterface $pointcut): bool
    {
        return $pointcut instanceof FieldInterceptionAwareInterface && $pointcut->requiresFieldInterception();
    }

    /**
     * @param list<JoinPointKind> $kinds
     */
    public static function anyKind(array $kinds): bool
    {
        foreach ($kinds as $kind) {
            if ($kind->requiresFieldInterception()) {
                return true;
            }
        }

        return false;
    }
}
