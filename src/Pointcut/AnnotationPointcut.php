<?php

declare(strict_types=1);

namespace AOP\Pointcut;

use AOP\Pointcut\Pattern\TypePattern;

/**
 * The method, function, constructor or field carries a PHP attribute of the given type (with `+`: or a subclass of it).
 */
final class AnnotationPointcut implements PointcutInterface, FieldInterceptionAwareInterface
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

    /**
     * `$ctx->property` makes this a field pointcut for an attribute that can only sit on properties (and so on fields
     * and nothing else). An attribute that may also sit on methods, functions or classes (`#[Attribute]` targets
     * everything) is accepted: under woven it simply does not see annotated properties. A wildcard, `+` or untyped
     * subject cannot be resolved to one attribute class, so it is accepted too.
     */
    public function requiresFieldInterception(): bool
    {
        $name = $this->subjectType?->exactName();
        if (null === $name || !class_exists($name)) {
            return false;
        }
        $declaration = (new \ReflectionClass($name))->getAttributes(\Attribute::class)[0] ?? null;
        $flags = $declaration?->newInstance()->flags ?? 0;

        return 0 !== ($flags & \Attribute::TARGET_PROPERTY)
            && 0 === ($flags & (\Attribute::TARGET_METHOD | \Attribute::TARGET_FUNCTION | \Attribute::TARGET_CLASS));
    }

    public function matchesRuntime(RuntimeMatchContext $ctx): bool
    {
        $member = $ctx->function() ?? $ctx->staticContext->property;

        return null === $this->binding || (null !== $member && $this->bindAttribute($ctx, $member));
    }
}
