<?php

declare(strict_types=1);

namespace AOP\Aspect;

use AOP\Advice\AdviceDefinition;
use AOP\Advice\AdviceType;
use AOP\Attribute\After;
use AOP\Attribute\AfterReturning;
use AOP\Attribute\AfterThrowing;
use AOP\Attribute\Around;
use AOP\Attribute\Aspect;
use AOP\Attribute\Before;
use AOP\Attribute\DeclareParents;
use AOP\Attribute\Order;
use AOP\Attribute\Pointcut;
use AOP\Exception\AspectDefinitionException;

final class AspectMetadataReader
{
    /**
     * @param class-string|\ReflectionClass<covariant object> $class
     */
    public function read(string|\ReflectionClass $class): AspectMetadata
    {
        $rc = $class instanceof \ReflectionClass ? $class : new \ReflectionClass($class);
        $name = $rc->getName();

        if ([] === $rc->getAttributes(Aspect::class)) {
            throw new AspectDefinitionException(sprintf('Class "%s" is not an aspect: missing #[Aspect] attribute.', $name));
        }

        $aspectAttribute = $rc->getAttributes(Aspect::class)[0]->newInstance();
        $scope = $aspectAttribute->scope;
        $order = ($rc->getAttributes(Order::class)[0] ?? null)?->newInstance()->value ?? PHP_INT_MAX;
        $pointcuts = [];
        $advices = [];

        foreach ($rc->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $name) {
                continue;
            }

            $where = sprintf('%s::%s()', $name, $method->getName());
            /** @var list<array{AdviceType, Before|After|Around|AfterReturning|AfterThrowing}> $found */
            $found = [];
            foreach (AdviceType::cases() as $type) {
                foreach ($method->getAttributes($type->attributeClass()) as $attr) {
                    /** @var Before|After|Around|AfterReturning|AfterThrowing $inst */
                    $inst = $attr->newInstance();
                    $found[] = [$type, $inst];
                }
            }
            $pointcutAttrs = $method->getAttributes(Pointcut::class);

            if ([] === $found && [] === $pointcutAttrs) {
                continue;
            }
            if (count($found) + count($pointcutAttrs) > 1) {
                throw new AspectDefinitionException(sprintf('%s carries more than one advice/pointcut attribute.', $where));
            }
            if (!$method->isPublic() || $method->isStatic()) {
                throw new AspectDefinitionException(sprintf('%s must be a public, non-static method to carry advice or pointcut attributes (found private/protected or static).', $where));
            }

            if ([] !== $pointcutAttrs) {
                $returnType = $method->getReturnType();
                if (!$returnType instanceof \ReflectionNamedType || 'void' !== $returnType->getName()) {
                    throw new AspectDefinitionException(sprintf('Pointcut method %s must return void.', $where));
                }
                if ($method->getNumberOfParameters() > 0) {
                    throw new AspectDefinitionException(sprintf('Pointcut method %s must not declare parameters.', $where));
                }
                $pointcuts[$method->getName()] = $pointcutAttrs[0]->newInstance()->expression;
                continue;
            }

            [$type, $instance] = $found[0];
            $advices[] = new AdviceDefinition(
                $name,
                $method->getName(),
                $type,
                $instance->pointcut,
                $instance instanceof AfterReturning ? $instance->returning : null,
                $instance instanceof AfterThrowing ? $instance->throwing : null,
                count($advices),
                $scope,
            );
        }

        return new AspectMetadata($name, $order, $pointcuts, $advices, self::introductions($rc), $scope, $aspectAttribute->pointcut);
    }

    /**
     * @param \ReflectionClass<covariant object> $aspect
     *
     * @return list<IntroductionDefinition>
     */
    private static function introductions(\ReflectionClass $aspect): array
    {
        $introductions = [];
        foreach ($aspect->getAttributes(DeclareParents::class) as $attribute) {
            $declaration = $attribute->newInstance();
            /** @var class-string $interface */
            $interface = ltrim($declaration->interface, '\\');
            /** @var class-string $impl */
            $impl = ltrim($declaration->defaultImpl, '\\');
            $problem = match (true) {
                !interface_exists($interface) => sprintf('"%s" is not an interface', $interface),
                !class_exists($impl) => sprintf('default implementation "%s" does not exist', $impl),
                !is_subclass_of($impl, $interface) => sprintf('default implementation "%s" does not implement "%s"', $impl, $interface),
                !self::instantiableWithoutArguments(new \ReflectionClass($impl)) => sprintf('default implementation "%s" must be a concrete class whose public constructor can be called without arguments', $impl),
                default => null,
            };
            if (null !== $problem) {
                throw new AspectDefinitionException(sprintf('Invalid #[DeclareParents] on aspect "%s": %s.', $aspect->getName(), $problem));
            }
            $introductions[] = new IntroductionDefinition($declaration->typePattern, $interface, $impl);
        }

        return $introductions;
    }

    /**
     * @param \ReflectionClass<object> $class
     */
    private static function instantiableWithoutArguments(\ReflectionClass $class): bool
    {
        $constructor = $class->getConstructor();

        return $class->isInstantiable() && (null === $constructor || 0 === $constructor->getNumberOfRequiredParameters());
    }
}
