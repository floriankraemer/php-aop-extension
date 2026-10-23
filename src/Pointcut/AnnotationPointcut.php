<?php

declare(strict_types=1);

namespace AOP\Pointcut;

use AOP\Pointcut\Pattern\TypePattern;

/**
 * The method, function, constructor or field carries a PHP attribute of the given type (with `+`: or a subclass of it).
 */
final class AnnotationPointcut implements PointcutInterface
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

    public function matchesMethod(?\ReflectionFunctionAbstract $method, StaticMatchContext $ctx): bool
    {
        $member = $method ?? $ctx->property;

        return null !== $member && $this->hasAttribute($member);
    }

    public function matchesRuntime(RuntimeMatchContext $ctx): bool
    {
        $member = $ctx->function() ?? $ctx->staticContext->property;

        return null === $this->binding || (null !== $member && $this->bindAttribute($ctx, $member));
    }
}
