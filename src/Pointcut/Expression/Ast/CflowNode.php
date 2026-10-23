<?php

declare(strict_types=1);

namespace AOP\Pointcut\Expression\Ast;

/**
 * cflow(inner), or cflowbelow(inner) when $below is set.
 */
final readonly class CflowNode implements NodeInterface
{
    public function __construct(public NodeInterface $inner, public bool $below = false)
    {
    }
}
