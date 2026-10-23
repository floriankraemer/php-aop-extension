<?php

declare(strict_types=1);

namespace AOP\Pointcut;

use AOP\Pointcut\Pattern\TypePattern;

/**
 * The target's class carries the attribute (the runtime class is known per decision, so this is static).
 */
final class AtTargetPointcut implements PointcutInterface
{
    use SubjectBinding;

    public function __construct(TypePattern|BindingSpec $subject)
    {
        $this->initSubject($subject);
    }

    public function matchesClass(?\ReflectionClass $class, StaticMatchContext $ctx): bool
    {
        return null !== $class && $this->hasAttribute($class);
    }

    public function matchesMethod(?\ReflectionFunctionAbstract $method, StaticMatchContext $ctx): bool
    {
        $class = JoinPointShape::targetClass($ctx, $method);

        return null !== $class && $this->hasAttribute($class);
    }

    public function matchesRuntime(RuntimeMatchContext $ctx): bool
    {
        $class = JoinPointShape::targetClass($ctx->staticContext, $ctx->function());

        return null === $this->binding || (null !== $class && $this->bindAttribute($ctx, $class));
    }
}
