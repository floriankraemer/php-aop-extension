<?php

declare(strict_types=1);

namespace AOP\Pointcut;

use AOP\JoinPoint\ConstructorSignature;
use AOP\JoinPoint\FieldSignature;
use AOP\JoinPoint\FunctionSignature;
use AOP\JoinPoint\JoinPointInterface;
use AOP\JoinPoint\JoinPointKind;
use AOP\JoinPoint\MethodSignature;

/**
 * Rebuilds the static shadow of a join point that is already running (a control flow entry), from its signature, its
 * objects and its enclosing static part. The service name and introduced interfaces are not recoverable.
 *
 * @internal
 */
final class StaticContextFactory
{
    private function __construct()
    {
    }

    public static function fromJoinPoint(JoinPointInterface $jp): ?StaticMatchContext
    {
        try {
            return self::build($jp);
        } catch (\ReflectionException) {
            return null;
        }
    }

    /**
     * @throws \ReflectionException
     */
    private static function build(JoinPointInterface $jp): StaticMatchContext
    {
        $signature = $jp->getSignature();
        $kind = $jp->getKind();
        $object = $jp->getTarget();
        $function = $property = $caught = null;
        $declaring = self::classOf($signature->getDeclaringTypeName());

        switch (true) {
            case $signature instanceof ConstructorSignature:
                $function = $signature->constructor;
                break;
            case $signature instanceof MethodSignature:
                $function = $signature->method;
                break;
            case $signature instanceof FunctionSignature:
                $function = $signature->function ?? (function_exists($signature->functionName) ? new \ReflectionFunction($signature->functionName) : null);
                break;
            case $signature instanceof FieldSignature:
                $property = $signature->property ?? (null === $declaring || !$declaring->hasProperty($signature->propertyName) ? null : $declaring->getProperty($signature->propertyName));
                $object ??= $jp->getThis();
                break;
            default: // catch clause
                $caught = self::classOf($signature->getName());
                $object ??= $jp->getThis();
        }

        $targetClass = null !== $object ? new \ReflectionObject($object) : ($signature instanceof FunctionSignature || JoinPointKind::EXCEPTION_HANDLER === $kind ? null : $declaring);

        return new StaticMatchContext($kind, $targetClass, $function, $property, $caught, self::caller($jp), null, []);
    }

    private static function caller(JoinPointInterface $jp): ?EnclosingContext
    {
        $enclosing = $jp->getEnclosingStaticPart();
        if (null === $enclosing) {
            return null;
        }
        $signature = $enclosing->signature;
        $function = match (true) {
            $signature instanceof ConstructorSignature => $signature->constructor,
            $signature instanceof MethodSignature => $signature->method,
            $signature instanceof FunctionSignature => $signature->function ?? (function_exists($signature->functionName) ? new \ReflectionFunction($signature->functionName) : null),
            default => null,
        };
        $thisObject = $jp->getKind()->isExecution() ? null : $jp->getThis();
        $class = null !== $thisObject ? new \ReflectionObject($thisObject) : self::classOf($signature->getDeclaringTypeName());

        return new EnclosingContext($class, $function, $jp->getSourceLocation()?->file);
    }

    /**
     * @return \ReflectionClass<object>|null
     */
    private static function classOf(?string $name): ?\ReflectionClass
    {
        return null !== $name && (class_exists($name) || interface_exists($name) || enum_exists($name) || trait_exists($name)) ? new \ReflectionClass($name) : null;
    }
}
