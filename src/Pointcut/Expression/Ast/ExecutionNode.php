<?php

declare(strict_types=1);

namespace AOP\Pointcut\Expression\Ast;

final readonly class ExecutionNode implements NodeInterface
{
    /**
     * @param list<string>         $modifiers     `public`, `!static`, ... (see ModifierPattern)
     * @param TypePatternNode|null $returnPattern null means `*`
     * @param TypePatternNode|null $declaringType null means any
     */
    public function __construct(
        public array $modifiers,
        public ?TypePatternNode $returnPattern,
        public ?TypePatternNode $declaringType,
        public string $namePattern,
        public ParamListNode $params,
    ) {
    }
}
