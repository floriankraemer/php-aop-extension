<?php

declare(strict_types=1);

namespace AOP\JoinPoint;

/**
 * A place in the code: the call site of a call join point, or the code enclosing any other join point.
 */
final readonly class SourceLocation
{
    public function __construct(
        public ?string $file = null,
        public ?int $line = null,
        public ?string $class = null,
        public ?string $function = null,
    ) {
    }

    public function toString(): string
    {
        $where = null === $this->file ? '<unknown>' : $this->file.(null === $this->line ? '' : ':'.$this->line);
        $code = null === $this->function ? null : (null === $this->class ? '' : $this->class.'::').$this->function;

        return null === $code ? $where : $code.' ('.$where.')';
    }
}
