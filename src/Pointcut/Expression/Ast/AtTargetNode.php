<?php

declare(strict_types=1);

namespace AOP\Pointcut\Expression\Ast;

final readonly class AtTargetNode implements NodeInterface
{
    public function __construct(public TypePatternNode|BindingCandidateNode $subject)
    {
    }
}
