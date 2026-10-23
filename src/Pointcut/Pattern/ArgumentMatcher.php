<?php

declare(strict_types=1);

namespace AOP\Pointcut\Pattern;

/**
 * Runtime check of one argument against a type pattern: `instanceof` semantics for classes and interfaces,
 * `get_debug_type()` for builtins, `?` accepts null.
 *
 * @internal
 */
final class ArgumentMatcher
{
    private const BUILTINS = ['int', 'string', 'float', 'bool', 'array', 'null', 'mixed', 'object'];

    private function __construct(private readonly ?TypePattern $class, private readonly string $builtin, private readonly bool $nullable)
    {
    }

    /**
     * @param string $type pattern or restriction, optionally `?`-prefixed
     */
    public static function fromName(string $type, bool $nullable = false): self
    {
        if (str_starts_with($type, '?')) {
            $type = substr($type, 1);
            $nullable = true;
        }
        $type = ltrim($type, '\\');

        return in_array($type, self::BUILTINS, true)
            ? new self(null, $type, $nullable)
            : new self(new TypePattern($type, false, true), '', $nullable);
    }

    /**
     * Builtin name when the pattern names a builtin type, null for class patterns.
     */
    public function builtin(): ?string
    {
        return null === $this->class ? $this->builtin : null;
    }

    public function matches(mixed $argument): bool
    {
        if (null === $argument) {
            return $this->nullable || in_array($this->builtin, ['null', 'mixed'], true);
        }
        if (null !== $this->class) {
            return is_object($argument) && $this->class->matchesType(new \ReflectionClass($argument));
        }

        return match ($this->builtin) {
            'mixed' => true,
            'object' => is_object($argument),
            default => get_debug_type($argument) === $this->builtin,
        };
    }
}
