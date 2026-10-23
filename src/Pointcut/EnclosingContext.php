<?php

declare(strict_types=1);

namespace AOP\Pointcut;

/**
 * The code that contains a join point (the caller, for call kinds; the accessing code for fields and handlers).
 */
final readonly class EnclosingContext
{
    /**
     * @param \ReflectionClass<object>|null $class the caller's runtime class (class of `$this`, or the called scope)
     */
    public function __construct(
        public ?\ReflectionClass $class = null,
        public ?\ReflectionFunctionAbstract $function = null,
        public ?string $file = null,
    ) {
    }
}
