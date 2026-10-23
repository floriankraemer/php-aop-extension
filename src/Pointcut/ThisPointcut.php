<?php

declare(strict_types=1);

namespace AOP\Pointcut;

use AOP\Pointcut\Pattern\TypePattern;

/**
 * The executing object (for call kinds, field accesses and handlers: the object whose code contains the join point)
 * is of the type. Static code and functions have no `this`, so they never match.
 */
final class ThisPointcut implements PointcutInterface
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

        return null !== $class && $this->test($class, $ctx);
    }

    public function matchesMethod(?\ReflectionFunctionAbstract $method, StaticMatchContext $ctx): bool
    {
        $class = JoinPointShape::thisClass($ctx, $method);

        return null !== $class && $this->test($class, $ctx);
    }

    public function matchesRuntime(RuntimeMatchContext $ctx): bool
    {
        $this_ = $ctx->this();
        if (null === $this_) {
            return false;
        }
        if (null !== $this->binding) {
            $ctx->bind($this->binding->name, $this_);
        }

        return true;
    }

    public function isCallerSensitive(): bool
    {
        return true; // reads the caller's object for call kinds
    }

    /**
     * @param \ReflectionClass<object> $class
     */
    private function test(\ReflectionClass $class, StaticMatchContext $ctx): bool
    {
        if (null === $this->subjectType) {
            return true;
        }
        if (JoinPointShape::isCode($ctx->kind)) {
            // The class also implements the introduced interfaces and their parents.
            foreach ($ctx->introducedInterfaces as $interface) {
                foreach ([$interface, ...(class_implements($interface) ?: [])] as $name) {
                    if ($this->subjectType->matches($name)) {
                        return true;
                    }
                }
            }
        }

        return $this->subjectType->matchesType($class);
    }
}
