<?php

declare(strict_types=1);

namespace AOP\Pointcut;

use AOP\Pointcut\Pattern\TypePattern;

/**
 * The class whose code contains the join point carries the attribute: the declaring class for executions, the
 * caller's class for call kinds, field accesses and handlers.
 */
final class AtWithinPointcut implements PointcutInterface
{
    use SubjectBinding;

    public function __construct(TypePattern|BindingSpec $subject)
    {
        $this->initSubject($subject);
    }

    public function matchesClass(?\ReflectionClass $class, StaticMatchContext $ctx): bool
    {
        return true;
    }

    public function isCallerSensitive(): bool
    {
        return true;
    }

    public function matchesMethod(?\ReflectionFunctionAbstract $method, StaticMatchContext $ctx): bool
    {
        $classes = JoinPointShape::enclosingClasses($ctx, $method);

        return [] !== $classes && $this->hasAttribute(...$classes);
    }

    public function matchesRuntime(RuntimeMatchContext $ctx): bool
    {
        $classes = JoinPointShape::enclosingClasses($ctx->staticContext, $ctx->function());

        return null === $this->binding || ([] !== $classes && $this->bindAttribute($ctx, ...$classes));
    }
}
