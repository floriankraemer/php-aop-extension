<?php

declare(strict_types=1);

namespace AOP\Pointcut\Expression\Ast;

/**
 * withincode(): the enclosing method pattern has the shape of an execution() pattern, so it reuses ExecutionNode.
 */
final readonly class WithincodeNode implements NodeInterface
{
    public function __construct(public ExecutionNode $pattern)
    {
    }
}
