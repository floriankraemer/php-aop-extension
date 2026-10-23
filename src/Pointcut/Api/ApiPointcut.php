<?php

declare(strict_types=1);

namespace AOP\Pointcut\Api;

use AOP\JoinPoint\JoinPointKind;
use AOP\Pointcut\PointcutInterface;
use AOP\Pointcut\RuntimeMatchContext;
use AOP\Pointcut\StaticMatchContext;

/**
 * Adapts a ClassFilter plus a MethodMatcher (Spring's Pointcut) to the library's PointcutInterface.
 *
 * It only matches execution join points whose kind is in `$kinds` (default: method and function executions),
 * because a Spring pointcut is about "method invocations on the target", never about calls, fields or handlers.
 */
final class ApiPointcut implements PointcutInterface
{
    /** @var list<JoinPointKind> */
    private readonly array $kinds;

    /**
     * @param list<JoinPointKind>|null $kinds join point kinds this pointcut applies to
     */
    public function __construct(
        private readonly ClassFilterInterface $classFilter,
        private readonly MethodMatcherInterface $methodMatcher,
        ?array $kinds = null,
    ) {
        $this->kinds = $kinds ?? [JoinPointKind::METHOD_EXECUTION, JoinPointKind::FUNCTION_EXECUTION];
    }

    public function getClassFilter(): ClassFilterInterface
    {
        return $this->classFilter;
    }

    public function getMethodMatcher(): MethodMatcherInterface
    {
        return $this->methodMatcher;
    }

    public function matchesClass(?\ReflectionClass $class, StaticMatchContext $ctx): bool
    {
        if (!in_array($ctx->kind, $this->kinds, true)) {
            return false;
        }

        return null === $class || $this->classFilter->matches($class);
    }

    public function matchesMethod(?\ReflectionFunctionAbstract $function, StaticMatchContext $ctx): bool
    {
        // The class filter is checked again: inside a union the class prefilter of the other branch must not leak.
        return null !== $function && $this->matchesClass($ctx->targetClass, $ctx) && $this->methodMatcher->matches($function, $ctx->targetClass);
    }

    public function isRuntime(): bool
    {
        return $this->methodMatcher->isRuntime();
    }

    public function matchesRuntime(RuntimeMatchContext $ctx): bool
    {
        $function = $ctx->function();

        return null !== $function && $this->methodMatcher->matchesRuntime($function, $ctx->targetClass(), $ctx->args());
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
