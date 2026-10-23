<?php

declare(strict_types=1);

namespace AOP\Pointcut\Expression\Ast;

final readonly class HandlerNode implements NodeInterface
{
    public function __construct(public TypePatternNode $type)
    {
    }
}
