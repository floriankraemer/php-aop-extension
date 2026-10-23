<?php

declare(strict_types=1);

namespace AOP\Pointcut\Expression\Ast;

/**
 * `[modifiers] [type] Declaring\Type[+]::$name`, shared by get() and set().
 */
final readonly class FieldPatternNode implements NodeInterface
{
    /**
     * @param list<string>         $modifiers
     * @param TypePatternNode|null $type        null means `*`
     * @param string               $namePattern the property name pattern without `$`
     */
    public function __construct(
        public array $modifiers,
        public ?TypePatternNode $type,
        public TypePatternNode $declaringType,
        public string $namePattern,
    ) {
    }
}
