<?php

declare(strict_types=1);

namespace AOP\Pointcut;

use AOP\JoinPoint\JoinPointKind;
use AOP\Pointcut\Pattern\MethodPattern;
use AOP\Pointcut\Pattern\ModifierPattern;
use AOP\Pointcut\Pattern\NamePattern;
use AOP\Pointcut\Pattern\ParameterPattern;
use AOP\Pointcut\Pattern\TypePattern;

/**
 * The execution of a method, constructor or function matching the pattern. Never matches any other kind
 * (calls, initialization, fields, handlers, advice execution).
 */
final class ExecutionPointcut implements PointcutInterface
{
    private readonly MethodPattern $pattern;

    /**
     * @param TypePattern|null $returnType    null = any (including no declared return type)
     * @param TypePattern|null $declaringType null = any
     */
    public function __construct(
        ModifierPattern $modifier,
        ?TypePattern $returnType,
        ?TypePattern $declaringType,
        NamePattern $name,
        ParameterPattern $params,
    ) {
        $this->pattern = new MethodPattern($modifier, $returnType, $declaringType, $name, $params);
    }

    public function matchesClass(?\ReflectionClass $class, StaticMatchContext $ctx): bool
    {
        return self::appliesTo($ctx->kind) && $this->pattern->matchesClass($class, $ctx->introducedInterfaces);
    }

    public function matchesMethod(?\ReflectionFunctionAbstract $method, StaticMatchContext $ctx): bool
    {
        if (null === $method || !self::appliesTo($ctx->kind)) {
            return false;
        }
        if ($method instanceof \ReflectionMethod === (JoinPointKind::FUNCTION_EXECUTION === $ctx->kind)) {
            return false;
        }

        return $this->pattern->matches($method, $ctx->targetClass, $ctx->introducedInterfaces);
    }

    private static function appliesTo(JoinPointKind $kind): bool
    {
        return JoinPointKind::METHOD_EXECUTION === $kind
            || JoinPointKind::CONSTRUCTOR_EXECUTION === $kind
            || JoinPointKind::FUNCTION_EXECUTION === $kind;
    }

    public function isRuntime(): bool
    {
        return false;
    }

    public function matchesRuntime(RuntimeMatchContext $ctx): bool
    {
        return true;
    }

    public function bindings(): array
    {
        return [];
    }

    public function isCallerSensitive(): bool
    {
        return false;
    }
}
