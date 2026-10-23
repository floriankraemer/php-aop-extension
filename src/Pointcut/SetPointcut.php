<?php

declare(strict_types=1);

namespace AOP\Pointcut;

use AOP\JoinPoint\JoinPointKind;

/**
 * A property write (`field-set`). `args()` of the join point is the single new value.
 */
final class SetPointcut extends FieldAccessPointcut
{
    protected function kind(): JoinPointKind
    {
        return JoinPointKind::FIELD_SET;
    }
}
