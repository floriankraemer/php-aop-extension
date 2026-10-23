<?php

declare(strict_types=1);

namespace AOP\Runtime;

/**
 * Does a value satisfy a declared return type? Mirrors PHP's strict-mode rules (int is accepted for float).
 */
final class ReturnTypeCheck
{
    /**
     * `static` accepts the proxy and the target: an interface proxy receives the raw target from `proceed()` and wraps it afterwards.
     */
    public static function accepts(?\ReflectionType $type, mixed $value, object $proxy, object $target, string $declaringClass): bool
    {
        if (null === $type) {
            return true;
        }
        if ($type instanceof \ReflectionUnionType) {
            foreach ($type->getTypes() as $member) {
                if (self::accepts($member, $value, $proxy, $target, $declaringClass)) {
                    return true;
                }
            }

            return false;
        }
        if ($type instanceof \ReflectionIntersectionType) {
            foreach ($type->getTypes() as $member) {
                if (!self::accepts($member, $value, $proxy, $target, $declaringClass)) {
                    return false;
                }
            }

            return true;
        }
        if (!$type instanceof \ReflectionNamedType) {
            return true;
        }
        if (null === $value && $type->allowsNull()) {
            return true;
        }
        $name = strtolower($type->getName());

        return match ($name) {
            'mixed' => true,
            'void', 'null' => null === $value,
            'never' => false,
            'int' => is_int($value),
            'float' => is_float($value) || is_int($value),
            'string' => is_string($value),
            'bool' => is_bool($value),
            'true' => true === $value,
            'false' => false === $value,
            'array' => is_array($value),
            'iterable' => is_iterable($value),
            'callable' => is_callable($value),
            'object' => is_object($value),
            'static' => $value instanceof $proxy || $value instanceof $target,
            'self' => $value instanceof $declaringClass,
            default => $value instanceof ($type->getName()),
        };
    }
}
