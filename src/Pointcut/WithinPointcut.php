<?php

declare(strict_types=1);

namespace AOP\Pointcut;

use AOP\Pointcut\Pattern\TypePattern;

/**
 * The code containing the join point lexically belongs to the type: the declaring class for executions, the
 * caller's class for call kinds, field accesses and handlers. Plain functions are not within any type.
 */
final class WithinPointcut implements PointcutInterface
{
    public function __construct(private readonly TypePattern $type)
    {
    }

    public function matchesClass(?\ReflectionClass $class, StaticMatchContext $ctx): bool
    {
        if (!JoinPointShape::isCode($ctx->kind)) {
            return true; // decided per caller in matchesMethod()
        }
        // An inherited method is within the class declaring it.
        while ($class instanceof \ReflectionClass) {
            if ($this->type->matchesType($class)) {
                return true;
            }
            $class = $class->getParentClass() ?: null;
        }

        return false;
    }

    public function matchesMethod(?\ReflectionFunctionAbstract $method, StaticMatchContext $ctx): bool
    {
        foreach (JoinPointShape::enclosingClasses($ctx, $method) as $class) {
            if ($this->type->matchesType($class)) {
                return true;
            }
        }

        return false;
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
        return true;
    }
}
