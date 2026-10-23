<?php

declare(strict_types=1);

namespace AOP\Pointcut\Expression\Ast;

/**
 * initialization(Type::__construct(..)); the shorthand initialization(Type+) is the same node with `(..)` parameters.
 */
final readonly class InitializationNode implements NodeInterface
{
    /**
     * @param list<string> $modifiers `public`, `!static`, ... (see ModifierPattern)
     */
    public function __construct(
        public array $modifiers,
        public TypePatternNode $declaringType,
        public ParamListNode $params,
    ) {
    }
}
