<?php

declare(strict_types=1);

namespace AOP\Runtime;

/**
 * Identifies one control flow of a percflow/percflowbelow aspect: the aspect instance lives as long as this object.
 *
 * @internal
 */
final readonly class FlowToken
{
    public object $key;

    public function __construct(public int $depth)
    {
        $this->key = new \stdClass();
    }
}
