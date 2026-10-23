<?php

declare(strict_types=1);

namespace AOP\Pointcut\Expression\Ast;

/**
 * initialization(Type::__construct(..)); the shorthand initialization(Type+) is the same node with `(..)` parameters.
 */
final readonly class InitializationNode implements NodeInterface
{
    /**
     * @param 'public'|'protected'|null $modifier
     */
    public function __construct(
        public ?string $modifier,
        public TypePatternNode $declaringType,
        public ParamListNode $params,
    ) {
    }
}
