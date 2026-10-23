<?php

declare(strict_types=1);

namespace AOP\Pointcut\Api;

use AOP\JoinPoint\JoinPointKind;
use AOP\Pointcut\PointcutInterface;
use AOP\Pointcut\RuntimeMatchContext;
use AOP\Pointcut\StaticMatchContext;

/**
 * The static half of a Spring ControlFlowPointcut: executions of a class (or a subtype of it) whose method name matches.
 * It is what the runtime has to TRACK for the flow to be visible on the control flow stack; it never selects advice.
 *
 * @internal
 */
final class ControlFlowEntryPointcut implements PointcutInterface
{
    /**
     * @param class-string $class
     */
    public function __construct(private readonly string $class, private readonly ?string $methodRegex)
    {
    }

    public function matchesClass(?\ReflectionClass $class, StaticMatchContext $ctx): bool
    {
        if (!self::isExecution($ctx->kind)) {
            return false;
        }
        $wanted = ltrim($this->class, '\\');

        // A function (no class) cannot be a member of the class. The declaring type may be an ancestor of the runtime class.
        return null !== $class && (0 === strcasecmp($class->getName(), $wanted) || is_a($class->getName(), $wanted, true));
    }

    public function matchesMethod(?\ReflectionFunctionAbstract $function, StaticMatchContext $ctx): bool
    {
        return null !== $function
            && self::isExecution($ctx->kind)
            && (null === $this->methodRegex || 1 === preg_match($this->methodRegex, $function->getName()));
    }

    private static function isExecution(JoinPointKind $kind): bool
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
