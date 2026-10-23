<?php

declare(strict_types=1);

namespace AOP\Pointcut\Pattern;

use AOP\Pointcut\Expression\Ast\TypePatternNode;

/**
 * Type name pattern: `*` within a segment, `**` as a whole segment for any number of segments,
 * trailing `+` (includeSubtypes) for the type or any subtype. Compiled to one regex.
 */
final class TypePattern
{
    private readonly string $regex;

    public function __construct(
        string $pattern,
        public readonly bool $nullable = false,
        public readonly bool $includeSubtypes = false,
    ) {
        $this->regex = self::compile(ltrim($pattern, '\\'));
    }

    public static function fromNode(TypePatternNode $node): self
    {
        return new self($node->pattern, $node->nullable, $node->includeSubtypes);
    }

    /**
     * Exact match of the pattern against a name, ignoring `+`.
     */
    public function matches(string $fqcn): bool
    {
        return 1 === preg_match($this->regex, ltrim($fqcn, '\\'));
    }

    /**
     * Like matches(), but with `+` the name may also be a class/interface that has a matching ancestor.
     */
    public function matchesName(string $name): bool
    {
        if ($this->matches($name)) {
            return true;
        }

        return $this->includeSubtypes
            && (class_exists($name) || interface_exists($name))
            && $this->matchesType(new \ReflectionClass($name));
    }

    /**
     * @param \ReflectionClass<object> $class
     */
    public function matchesType(\ReflectionClass $class): bool
    {
        if ($this->matches($class->getName())) {
            return true;
        }
        if (!$this->includeSubtypes) {
            return false;
        }
        for ($parent = $class->getParentClass(); false !== $parent; $parent = $parent->getParentClass()) {
            if ($this->matches($parent->getName())) {
                return true;
            }
        }
        foreach ($class->getInterfaceNames() as $interface) {
            if ($this->matches($interface)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Matches a declared parameter/return type. Untyped, union and intersection types never match.
     * A nullable declared type (`?T`) matches only a nullable pattern (`?T`), never the plain `T`.
     */
    /**
     * `self` and `parent` are matched both as written and as the class they resolve to in `$declaring`, since PHP
     * versions differ in whether reflection reports the keyword or the resolved class name.
     *
     * @param \ReflectionClass<object>|null $declaring the class declaring the method or parameter
     */
    public function matchesDeclared(?\ReflectionType $type, ?\ReflectionClass $declaring = null): bool
    {
        if (!$type instanceof \ReflectionNamedType) {
            return false;
        }
        $name = $type->getName();
        $nullable = $type->allowsNull() && !in_array($name, ['mixed', 'null'], true);
        if ($nullable !== $this->nullable) {
            return false;
        }
        if ($this->matchesName($name)) {
            return true;
        }
        if (null === $declaring) {
            return false;
        }
        $aliases = ['self' => $declaring->getName(), 'parent' => $declaring->getParentClass() ? $declaring->getParentClass()->getName() : null];
        foreach ($aliases as $keyword => $resolved) {
            if (null !== $resolved && ($name === $keyword || $name === $resolved) && ($this->matchesName($keyword) || $this->matchesName($resolved))) {
                return true;
            }
        }

        return false;
    }

    private static function compile(string $pattern): string
    {
        $segments = explode('\\', $pattern);
        $last = count($segments) - 1;
        $regex = '';
        foreach ($segments as $i => $segment) {
            if ('**' === $segment) {
                $regex .= $i === $last ? '.+' : '(?:[^\\\\]+\\\\)*';
                continue;
            }
            $regex .= '*' === $segment ? '[^\\\\]+' : str_replace('\*', '[^\\\\]*', preg_quote($segment, '/'));
            if ($i !== $last) {
                $regex .= '\\\\';
            }
        }

        return '/^'.$regex.'$/D';
    }
}
