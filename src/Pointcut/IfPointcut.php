<?php

declare(strict_types=1);

namespace AOP\Pointcut;

use AOP\Exception\PointcutSyntaxException;
use AOP\JoinPoint\JoinPointInterface;

/**
 * `if(Class::method())`: calls a static method per invocation; the join point matches when it returns true.
 * The method may declare one parameter of type JoinPointInterface (it then receives the current join point) and must
 * return `bool` (declared, or at least returned: anything else throws at runtime).
 */
final class IfPointcut implements PointcutInterface
{
    private readonly bool $takesJoinPoint;
    private ?\ReflectionMethod $method = null;

    /**
     * @throws PointcutSyntaxException when the method does not exist, is not static or has an unsupported signature
     */
    public function __construct(public readonly string $className, public readonly string $methodName)
    {
        $label = sprintf('if(%s::%s())', $className, $methodName);
        if (!class_exists($className) && !enum_exists($className)) {
            throw new PointcutSyntaxException(sprintf('%s: class %s does not exist.', $label, $className));
        }
        $reflection = new \ReflectionClass($className);
        if (!$reflection->hasMethod($methodName)) {
            throw new PointcutSyntaxException(sprintf('%s: %s has no method %s().', $label, $className, $methodName));
        }
        $method = $reflection->getMethod($methodName);
        if (!$method->isStatic()) {
            throw new PointcutSyntaxException(sprintf('%s: %s::%s() must be static.', $label, $className, $methodName));
        }
        $returnType = $method->getReturnType();
        if (null !== $returnType && !($returnType instanceof \ReflectionNamedType && 'bool' === $returnType->getName() && !$returnType->allowsNull())) {
            throw new PointcutSyntaxException(sprintf('%s: %s::%s() must return bool, it returns %s.', $label, $className, $methodName, $returnType));
        }
        $parameters = $method->getParameters();
        if (count($parameters) > 1 || (1 === count($parameters) && !self::acceptsJoinPoint($parameters[0]))) {
            throw new PointcutSyntaxException(sprintf('%s: %s::%s() may only declare one parameter of type %s.', $label, $className, $methodName, JoinPointInterface::class));
        }
        $this->takesJoinPoint = 1 === count($parameters);
        $this->method = $method;
    }

    public function matchesClass(?\ReflectionClass $class, StaticMatchContext $ctx): bool
    {
        return true;
    }

    public function matchesMethod(?\ReflectionFunctionAbstract $method, StaticMatchContext $ctx): bool
    {
        return true;
    }

    public function isRuntime(): bool
    {
        return true;
    }

    public function matchesRuntime(RuntimeMatchContext $ctx): bool
    {
        $method = $this->method ??= new \ReflectionMethod($this->className, $this->methodName);
        $result = $method->invoke(null, ...($this->takesJoinPoint ? [$ctx->joinPoint] : []));
        if (!is_bool($result)) {
            throw new \UnexpectedValueException(sprintf('if(%s::%s()) must return bool, got %s.', $this->className, $this->methodName, get_debug_type($result)));
        }

        return $result;
    }

    public function bindings(): array
    {
        return [];
    }

    public function isCallerSensitive(): bool
    {
        return false;
    }

    private static function acceptsJoinPoint(\ReflectionParameter $parameter): bool
    {
        $type = $parameter->getType();
        if (null === $type) {
            return true;
        }

        if (!$type instanceof \ReflectionNamedType) {
            return false;
        }

        return in_array($type->getName(), ['mixed', 'object'], true) || (!$type->isBuiltin() && is_a(JoinPointInterface::class, $type->getName(), true));
    }
}
