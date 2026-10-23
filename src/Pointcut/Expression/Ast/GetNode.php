<?php

declare(strict_types=1);

namespace AOP\Pointcut\Expression\Ast;

final readonly class GetNode implements NodeInterface
{
    public function __construct(public FieldPatternNode $field)
    {
    }
}
