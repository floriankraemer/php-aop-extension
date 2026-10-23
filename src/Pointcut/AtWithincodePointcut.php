<?php

declare(strict_types=1);

namespace AOP\Pointcut;

use AOP\Pointcut\Pattern\TypePattern;

/**
 * The function containing the join point carries the attribute: the executing function for execution kinds, the
 * caller's function for calls, field accesses and handlers. With a binding, binds the attribute instance.
 */
final class AtWithincodePointcut implements PointcutInterface
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
        $function = JoinPointShape::enclosingFunction($ctx, $method);

        return null !== $function && $this->hasAttribute($function);
    }

    public function matchesRuntime(RuntimeMatchContext $ctx): bool
    {
        $function = JoinPointShape::enclosingFunction($ctx->staticContext, $ctx->function());

        return null === $this->binding || (null !== $function && $this->bindAttribute($ctx, $function));
    }

    public function isCallerSensitive(): bool
    {
        return true;
    }
}
