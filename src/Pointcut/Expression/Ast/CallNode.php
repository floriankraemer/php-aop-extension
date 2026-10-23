<?php

declare(strict_types=1);

namespace AOP\Pointcut\Expression\Ast;

/**
 * call(): the callee pattern has the shape of an execution() pattern, so it reuses ExecutionNode.
 */
final readonly class CallNode implements NodeInterface
{
    public function __construct(public ExecutionNode $pattern)
    {
    }
}
