<?php

declare(strict_types=1);

namespace AOP\Pointcut\Expression\Ast;

final readonly class NotNode implements NodeInterface
{
    public function __construct(public NodeInterface $inner)
    {
    }
}
