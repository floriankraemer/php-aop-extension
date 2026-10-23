<?php

declare(strict_types=1);

namespace AOP\Pointcut;

use AOP\Pointcut\Pattern\TypePattern;

/**
 * The target's class carries the attribute (the target class is known when weaving, so this is static).
 */
final class AtTargetPointcut implements PointcutInterface
{
    use SubjectBinding;

    public function __construct(TypePattern|BindingSpec $subject)
    {
        $this->initSubject($subject);
    }

    public function matchesClass(\ReflectionClass $class, StaticMatchContext $ctx): bool
    {
        return $this->hasAttribute($class);
    }

    public function matchesMethod(\ReflectionMethod $method, StaticMatchContext $ctx): bool
    {
        return $this->hasAttribute($ctx->targetClass);
    }

    public function matchesRuntime(RuntimeMatchContext $ctx): bool
    {
        return null === $this->binding || $this->bindAttribute($ctx, $ctx->targetClass());
    }
}
