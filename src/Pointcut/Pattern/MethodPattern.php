<?php

declare(strict_types=1);

namespace AOP\Pointcut\Pattern;

use AOP\Pointcut\Expression\Ast\ExecutionNode;

/**
 * `[modifier] [return type] [Declaring\Type[+]::]name(params)`: the method, constructor and function pattern shared by
 * execution(), call(), withincode() and initialization().
 *
 * A function pattern has no declaring type and may be namespaced; a declaring type never matches a plain function. A plain
 * function counts as public, non-static, non-final and non-abstract for the modifiers.
 *
 * Constructors are not overridden (AspectJ semantics): the declaring type of a constructor pattern matches the class that
 * declares the executing constructor (`Base+` also its subclasses), so `Base::__construct` never matches the own
 * constructor of a subclass. A class that inherits a constructor is matched by its own name as well. Every other method
 * matches through the type that declares the signature, so an override is matched by the pattern of its base type.
 */
final class MethodPattern
{
    /**
     * @param TypePattern|null $returnType    null = any (including no declared return type)
     * @param TypePattern|null $declaringType null = any
     */
    public function __construct(
        private readonly ModifierPattern $modifier,
        private readonly ?TypePattern $returnType,
        private readonly ?TypePattern $declaringType,
        private readonly NamePattern $name,
        private readonly ParameterPattern $params,
    ) {
    }

    public static function fromNode(ExecutionNode $node): self
    {
        return new self(
            new ModifierPattern($node->modifiers),
            null === $node->returnPattern ? null : TypePattern::fromNode($node->returnPattern),
            null === $node->declaringType ? null : TypePattern::fromNode($node->declaringType),
            new NamePattern($node->namePattern),
            ParameterPattern::fromNode($node->params),
        );
    }

    /**
     * Cheap prefilter on the class: false only when no member of it can match.
     *
     * @param \ReflectionClass<object>|null $class
     * @param list<class-string>            $introducedInterfaces
     */
    public function matchesClass(?\ReflectionClass $class, array $introducedInterfaces = []): bool
    {
        if (null === $this->declaringType) {
            return true;
        }
        if (null === $class) {
            return false; // a function has no declaring type
        }
        foreach ([...self::hierarchy($class), ...self::introduced($introducedInterfaces)] as $type) {
            if ($this->declaringType->matchesType($type)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param \ReflectionClass<object>|null $class                the runtime class of the member's object; defaults to the declaring class
     * @param list<class-string>            $introducedInterfaces
     */
    public function matches(\ReflectionFunctionAbstract $function, ?\ReflectionClass $class = null, array $introducedInterfaces = []): bool
    {
        if (!$function instanceof \ReflectionMethod && null !== $this->declaringType) {
            return false;
        }

        return $this->modifier->matches($function)
            && $this->name->matches($function->getName())
            && $this->params->matches($function)
            && (null === $this->returnType || $this->returnType->matchesDeclared($function->getReturnType(), $function instanceof \ReflectionMethod ? $function->getDeclaringClass() : null))
            && ($function instanceof \ReflectionMethod && 0 === strcasecmp($function->getName(), '__construct') ? $this->constructorTypeMatches($function, $class) : $this->declaringTypeMatches($function, $class, $introducedInterfaces));
    }

    /**
     * A constructor is not overridden: the pattern matches the class declaring it, or the runtime class when that class
     * inherits the constructor (and therefore has no declaration of its own).
     *
     * @param \ReflectionClass<object>|null $class
     */
    private function constructorTypeMatches(\ReflectionMethod $constructor, ?\ReflectionClass $class): bool
    {
        if (null === $this->declaringType) {
            return true;
        }
        $declaring = $constructor->getDeclaringClass();
        if ($this->declaringType->matchesType($declaring)) {
            return true;
        }
        if (null === $class || $class->getName() === $declaring->getName()) {
            return false;
        }
        $own = $class->getConstructor();

        return null !== $own && $own->getDeclaringClass()->getName() === $declaring->getName() && $this->declaringType->matchesType($class);
    }

    /**
     * Does the pattern accept a constructor that is not declared (no parameters, public)?
     *
     * @param \ReflectionClass<object> $class
     * @param list<class-string>       $introducedInterfaces
     */
    public function matchesImplicitConstructor(\ReflectionClass $class, array $introducedInterfaces = []): bool
    {
        return $this->modifier->allowsImplicitConstructor()
            && null === $this->returnType
            && $this->name->matches('__construct')
            && $this->params->matchesNoParameters()
            && (null === $this->declaringType || $this->declaringType->matchesType($class));
    }

    /**
     * A method: the runtime class itself, or any ancestor/interface that declares it. An introduced method (declared
     * by an introduced interface): that interface, or any ancestor of it that declares it.
     *
     * @param \ReflectionClass<object>|null $class
     * @param list<class-string>            $introducedInterfaces
     */
    private function declaringTypeMatches(\ReflectionFunctionAbstract $function, ?\ReflectionClass $class, array $introducedInterfaces): bool
    {
        if (null === $this->declaringType) {
            return true;
        }
        if (!$function instanceof \ReflectionMethod) {
            return false;
        }
        $class ??= $function->getDeclaringClass();
        if ($class->hasMethod($function->getName()) && $this->declaredBy($class, $function->getName())) {
            return true;
        }
        foreach (self::introduced($introducedInterfaces) as $interface) {
            if ($interface->hasMethod($function->getName()) && $this->declaredBy($interface, $function->getName())) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param \ReflectionClass<object> $root
     */
    private function declaredBy(\ReflectionClass $root, string $method): bool
    {
        if (null === $this->declaringType || $this->declaringType->matchesType($root)) {
            return true;
        }
        foreach (self::hierarchy($root) as $type) {
            if ($type->hasMethod($method)
                && $type->getMethod($method)->getDeclaringClass()->getName() === $type->getName()
                && $this->declaringType->matchesType($type)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<class-string> $interfaces
     *
     * @return list<\ReflectionClass<object>>
     */
    private static function introduced(array $interfaces): array
    {
        return array_map(static fn (string $interface): \ReflectionClass => new \ReflectionClass($interface), $interfaces);
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
