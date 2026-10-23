<?php

declare(strict_types=1);

namespace AOP\Pointcut;

use AOP\JoinPoint\JoinPointKind;

/**
 * A property read (`field-get`). `args()` of the join point is empty; the value is only available as the result.
 */
final class GetPointcut extends FieldAccessPointcut
{
    protected function kind(): JoinPointKind
    {
        return JoinPointKind::FIELD_GET;
    }
}
