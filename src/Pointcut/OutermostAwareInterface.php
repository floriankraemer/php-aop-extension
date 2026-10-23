<?php

declare(strict_types=1);

namespace AOP\Pointcut;

/**
 * Implemented by the join point the runtime hands to runtime pointcuts when it knows whether a `constructor-execution`
 * is the object's outermost constructor (the one `new` called, not a `parent::__construct()`).
 * A join point that does not implement it is taken as outermost.
 */
interface OutermostAwareInterface
{
    public function isOutermost(): bool;
}
