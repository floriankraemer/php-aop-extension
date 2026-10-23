<?php

declare(strict_types=1);

namespace AOP\Pointcut\Expression\Ast;

final readonly class AndNode implements NodeInterface
{
    public function __construct(public NodeInterface $left, public NodeInterface $right)
    {
    }
}
