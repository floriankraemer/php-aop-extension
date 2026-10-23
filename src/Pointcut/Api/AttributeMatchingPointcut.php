<?php

declare(strict_types=1);

namespace AOP\Pointcut\Api;

use AOP\Exception\PointcutSyntaxException;

/**
 * Matches classes and/or methods carrying an attribute (Spring's AnnotationMatchingPointcut).
 *
 * With both given, a method matches when its class has the class attribute AND it has the method attribute.
 * Attributes match by `instanceof`, so a subclass of the attribute class counts.
 *
 * PHP attributes are not inherited. With `$checkInherited`, the class attribute is also looked up on parent
 * classes, implemented interfaces and used traits, and the method attribute on the same method of parent classes
 * and interfaces (what an override inherits in Spring's `@Inherited`/`findAnnotation` model).
 */
final class AttributeMatchingPointcut extends StaticMethodMatcherPointcut
{
    /**
     * @param class-string|null $classAttribute
     * @param class-string|null $methodAttribute
     *
     * @throws PointcutSyntaxException when neither attribute is given
     */
    public function __construct(
        private readonly ?string $classAttribute = null,
        private readonly ?string $methodAttribute = null,
        private readonly bool $checkInherited = false,
    ) {
        if (null === $classAttribute && null === $methodAttribute) {
            throw new PointcutSyntaxException('AttributeMatchingPointcut needs a class attribute, a method attribute or both.');
        }
        $this->setClassFilter(new class($classAttribute, $checkInherited) implements ClassFilterInterface {
            /** @param class-string|null $attribute */
            public function __construct(private readonly ?string $attribute, private readonly bool $inherited)
            {
            }

            public function matches(\ReflectionClass $class): bool
            {
                return null === $this->attribute || AttributeMatchingPointcut::classHas($class, $this->attribute, $this->inherited);
            }
        });
    }

    public function matches(\ReflectionFunctionAbstract $function, ?\ReflectionClass $class): bool
    {
        if (null !== $this->classAttribute && null === $class) {
            return false;
        }
        if (null === $this->methodAttribute) {
            return true;
        }
        if ([] !== $function->getAttributes($this->methodAttribute, \ReflectionAttribute::IS_INSTANCEOF)) {
            return true;
        }

        return $this->checkInherited && $function instanceof \ReflectionMethod && self::inheritedMethodHas($function, $this->methodAttribute);
    }

    /**
     * @param \ReflectionClass<object> $class
     * @param class-string             $attribute
     *
     * @internal
     */
    public static function classHas(\ReflectionClass $class, string $attribute, bool $inherited): bool
    {
        if ([] !== $class->getAttributes($attribute, \ReflectionAttribute::IS_INSTANCEOF)) {
            return true;
        }
        if (!$inherited) {
            return false;
        }
        $others = [...array_values(class_parents($class->getName()) ?: []), ...array_values(class_implements($class->getName()) ?: [])];
        foreach ($class->getTraitNames() as $trait) {
            $others[] = $trait;
        }
        foreach ($others as $other) {
            if ([] !== (new \ReflectionClass($other))->getAttributes($attribute, \ReflectionAttribute::IS_INSTANCEOF)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param class-string $attribute
     */
    private static function inheritedMethodHas(\ReflectionMethod $method, string $attribute): bool
    {
        $declaring = $method->getDeclaringClass()->getName();
        $others = [...array_values(class_parents($declaring) ?: []), ...array_values(class_implements($declaring) ?: [])];
        foreach ($others as $other) {
            $reflection = new \ReflectionClass($other);
            if ($reflection->hasMethod($method->getName()) && [] !== $reflection->getMethod($method->getName())->getAttributes($attribute, \ReflectionAttribute::IS_INSTANCEOF)) {
                return true;
            }
        }

        return false;
    }
}
