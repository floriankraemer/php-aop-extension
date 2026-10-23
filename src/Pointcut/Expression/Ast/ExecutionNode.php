<?php

declare(strict_types=1);

namespace AOP\Pointcut\Expression\Ast;

final readonly class ExecutionNode implements NodeInterface
{
    /**
     * @param 'public'|'protected'|null $modifier
     * @param TypePatternNode|null      $returnPattern null means `*`
     * @param TypePatternNode|null      $declaringType null means any
     */
    public function __construct(
        public ?string $modifier,
        public ?TypePatternNode $returnPattern,
        public ?TypePatternNode $declaringType,
        public string $namePattern,
        public ParamListNode $params,
    ) {
    }
}
