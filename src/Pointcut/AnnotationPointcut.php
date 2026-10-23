<?php

declare(strict_types=1);

namespace AOP\Pointcut;

use AOP\Pointcut\Pattern\TypePattern;

/**
 * The method carries a PHP attribute of the given type (with `+`: or a subclass of it).
 */
final class AnnotationPointcut implements PointcutInterface
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
        return $this->hasAttribute($method);
    }

    public function matchesRuntime(RuntimeMatchContext $ctx): bool
    {
        return null === $this->binding || $this->bindAttribute($ctx, $ctx->method);
    }
}
