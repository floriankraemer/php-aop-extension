<?php

declare(strict_types=1);

namespace AOP\Pointcut;

use AOP\Pointcut\Pattern\TypePattern;

/**
 * The target class or the class declaring the method carries the attribute.
 */
final class AtWithinPointcut implements PointcutInterface
{
    use SubjectBinding;

    public function __construct(TypePattern|BindingSpec $subject)
    {
        $this->initSubject($subject);
    }

    public function matchesClass(\ReflectionClass $class, StaticMatchContext $ctx): bool
    {
        return true;
    }

    public function matchesMethod(\ReflectionMethod $method, StaticMatchContext $ctx): bool
    {
        return $this->hasAttribute($ctx->targetClass, $method->getDeclaringClass());
    }

    public function matchesRuntime(RuntimeMatchContext $ctx): bool
    {
        return null === $this->binding
            || $this->bindAttribute($ctx, $ctx->targetClass(), $ctx->method->getDeclaringClass());
    }
}
