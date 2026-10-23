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

    public function matchesClass(\ReflectionClass $class, StaticMatchContext $ctx): bool
    {
        return null === $this->subjectType || $this->subjectType->matchesType($class);
    }

    public function matchesMethod(\ReflectionMethod $method, StaticMatchContext $ctx): bool
    {
        return $this->matchesClass($ctx->targetClass, $ctx);
    }

    public function matchesRuntime(RuntimeMatchContext $ctx): bool
    {
        if (null !== $this->binding) {
            $ctx->bind($this->binding->name, $ctx->target);
        }

        return true;
    }
}
