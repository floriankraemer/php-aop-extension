<?php

declare(strict_types=1);

namespace AOP\Pointcut\Pattern;

/**
 * `[modifiers] [type] Declaring\Type[+]::$name`: the property pattern of get() and set().
 *
 * Modifiers (`public`, `protected`, `private`, `static`, `readonly`) must all hold. The type pattern is checked against
 * the declared type like a return type (`*`/absent matches everything, also untyped properties). The declaring type
 * matches the runtime class or a class in its hierarchy that declares the property, like execution() does for methods.
 */
final class FieldPattern
{
    public const MODIFIERS = ['public', 'protected', 'private', 'static', 'readonly'];

    /**
     * @param list<string>     $modifiers
     * @param TypePattern|null $type          null = any
     * @param TypePattern|null $declaringType null = any
     */
    public function __construct(
        private readonly array $modifiers,
        private readonly ?TypePattern $type,
        private readonly ?TypePattern $declaringType,
        private readonly NamePattern $name,
    ) {
    }

    /**
     * @param \ReflectionClass<object>|null $class
     */
    public function matchesClass(?\ReflectionClass $class): bool
    {
        if (null === $this->declaringType) {
            return true;
        }
        if (null === $class) {
            return false;
        }
        foreach (self::hierarchy($class) as $type) {
            if ($this->declaringType->matchesType($type)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param \ReflectionClass<object>|null $class the runtime class; defaults to the declaring class
     */
    public function matches(\ReflectionProperty $property, ?\ReflectionClass $class = null): bool
    {
        return $this->name->matches($property->getName())
            && $this->modifiersMatch($property)
            && (null === $this->type || $this->type->matchesDeclared($property->getType(), $property->getDeclaringClass()))
            && $this->declaringTypeMatches($property, $class ?? $property->getDeclaringClass());
    }

    private function modifiersMatch(\ReflectionProperty $property): bool
    {
        foreach ($this->modifiers as $modifier) {
            $holds = match ($modifier) {
                'public' => $property->isPublic(),
                'protected' => $property->isProtected(),
                'private' => $property->isPrivate(),
                'static' => $property->isStatic(),
                'readonly' => $property->isReadOnly(),
                default => false,
            };
            if (!$holds) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param \ReflectionClass<object> $class
     */
    private function declaringTypeMatches(\ReflectionProperty $property, \ReflectionClass $class): bool
    {
        return null === $this->declaringType
            || $this->declaringType->matchesType($class)
            || $this->declaringType->matchesType($property->getDeclaringClass());
    }

    /**
     * @param \ReflectionClass<object> $class
     *
     * @return list<\ReflectionClass<object>> the class, its parents and all interfaces
     */
    private static function hierarchy(\ReflectionClass $class): array
    {
        $types = [$class];
        for ($parent = $class->getParentClass(); false !== $parent; $parent = $parent->getParentClass()) {
            $types[] = $parent;
        }
        foreach ($class->getInterfaces() as $interface) {
            $types[] = $interface;
        }

        return $types;
    }
}
