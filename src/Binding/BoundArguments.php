<?php

declare(strict_types=1);

namespace AOP\Binding;

final readonly class BoundArguments
{
    /**
     * @param list<mixed> $values positional arguments for the advice method
     */
    public function __construct(public array $values)
    {
    }
}
