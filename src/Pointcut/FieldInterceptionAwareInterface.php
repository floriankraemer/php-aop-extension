<?php

declare(strict_types=1);

namespace AOP\Pointcut;

use AOP\JoinPoint\JoinPointKind;

/**
 * Implemented by pointcuts that can select join points only observable with field interception (see
 * JoinPointKind::requiresFieldInterception()) and by the combinators and wrappers containing them. A pointcut without
 * this interface is assumed not to need it.
 */
interface FieldInterceptionAwareInterface
{
    public function requiresFieldInterception(): bool;
}
