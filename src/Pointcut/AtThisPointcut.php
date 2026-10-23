<?php

declare(strict_types=1);

namespace AOP\Pointcut;

use AOP\Pointcut\Pattern\TypePattern;

/**
 * The class of the executing object (for calls, field accesses and handlers: the object whose code contains the join
 * point) carries the attribute. Static code and functions have no `this`, so they never match. Decided statically
 * because the runtime class is part of the decision; with a binding the pointcut is runtime and binds the instance.
 */
final class AtThisPointcut implements PointcutInterface
{
    use SubjectBinding;

    public function __construct(TypePattern|BindingSpec $subject)
    {
        $this->initSubject($subject);
    }

    public function matchesClass(?\ReflectionClass $class, StaticMatchContext $ctx): bool
    {
        if (!JoinPointShape::isCode($ctx->kind)) {
            return true; // decided per caller in matchesMethod()
        }

        return null !== $class && $this->hasAttribute($class);
    }

    public function matchesMethod(?\ReflectionFunctionAbstract $method, StaticMatchContext $ctx): bool
    {
        $class = JoinPointShape::thisClass($ctx, $method);

        return null !== $class && $this->hasAttribute($class);
    }

    public function matchesRuntime(RuntimeMatchContext $ctx): bool
    {
        $this_ = $ctx->this();
        if (null === $this_) {
            return false;
        }

        return null === $this->binding || $this->bindAttribute($ctx, new \ReflectionObject($this_));
    }

    public function isCallerSensitive(): bool
    {
        return true; // reads the caller's object for call kinds
    }
}
