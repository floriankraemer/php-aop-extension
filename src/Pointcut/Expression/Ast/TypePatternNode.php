<?php

declare(strict_types=1);

namespace AOP\Pointcut\Expression\Ast;

final readonly class TypePatternNode implements NodeInterface
{
    public function __construct(public string $pattern, public bool $nullable = false, public bool $includeSubtypes = false)
    {
    }
}
