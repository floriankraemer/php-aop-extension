<?php

declare(strict_types=1);

namespace AOP\Pointcut;

use AOP\Pointcut\Pattern\TypePattern;

final class TargetPointcut implements PointcutInterface
{
    use SubjectBinding;

    public function __construct(TypePattern|BindingSpec $subject)
    {
        $this->initSubject($subject);
    }

    public function matchesClass(?\ReflectionClass $class, StaticMatchContext $ctx): bool
    {
        return null !== $class && (null === $this->subjectType || $this->subjectType->matchesType($class));
    }

    public function matchesMethod(?\ReflectionFunctionAbstract $method, StaticMatchContext $ctx): bool
    {
        $class = JoinPointShape::targetClass($ctx, $method);

        return null !== $class && $this->matchesClass($class, $ctx);
    }

    public function matchesRuntime(RuntimeMatchContext $ctx): bool
    {
        $target = $ctx->target();
        if (null === $target) {
            return false;
        }
        if (null !== $this->binding) {
            $ctx->bind($this->binding->name, $target);
        }

        return true;
    }
}
