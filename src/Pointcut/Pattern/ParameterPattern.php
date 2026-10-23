<?php

declare(strict_types=1);

namespace AOP\Pointcut\Pattern;

use AOP\Pointcut\Expression\Ast\ParamListNode;
use AOP\Pointcut\Expression\Ast\TypePatternNode;
use AOP\Pointcut\Expression\Ast\WildcardParamNode;

/**
 * Parameter list of an execution() spec: `()`, `(..)`, `(*)`, `(int, ..)`, ...
 * Bare identifiers inside execution params are type names.
 */
final class ParameterPattern
{
    /**
     * @param list<TypePattern|null> $params null = `*` (exactly one parameter of any type)
     */
    public function __construct(private readonly array $params, private readonly bool $trailingEllipsis)
    {
    }

    public static function fromNode(ParamListNode $node): self
    {
        $params = [];
        foreach ($node->params as $param) {
            $params[] = match (true) {
                $param instanceof WildcardParamNode => null,
                $param instanceof TypePatternNode => TypePattern::fromNode($param),
                default => new TypePattern($param->name),
            };
        }

        return new self($params, $node->trailingEllipsis);
    }

    public function matches(\ReflectionFunctionAbstract $method): bool
    {
        $count = $method->getNumberOfParameters();
        if ($this->trailingEllipsis ? $count < count($this->params) : $count !== count($this->params)) {
            return false;
        }

        foreach ($method->getParameters() as $i => $parameter) {
            if (!isset($this->params[$i])) {
                if ($i < count($this->params)) {
                    continue; // wildcard
                }
                break; // covered by the trailing ellipsis
            }
            if (!$this->params[$i]->matchesDeclared($parameter->getType(), $parameter->getDeclaringClass())) {
                return false;
            }
        }

        return true;
    }
}
