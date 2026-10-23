<?php

declare(strict_types=1);

namespace AOP\Pointcut;

use AOP\Pointcut\Pattern\TypePattern;
use AOP\Proxy\ProxyStrategy;

/**
 * Subclass proxy: the proxy is a target subclass, so the whole target hierarchy counts.
 * Interface proxy: only the implemented interfaces count.
 * Either way the proxy also implements the introduced interfaces.
 */
final class ThisPointcut implements PointcutInterface
{
    use SubjectBinding;

    public function __construct(TypePattern|BindingSpec $subject)
    {
        $this->initSubject($subject);
    }

    public function matchesClass(\ReflectionClass $class, StaticMatchContext $ctx): bool
    {
        return $this->test($class, $ctx);
    }

    public function matchesMethod(\ReflectionMethod $method, StaticMatchContext $ctx): bool
    {
        return $this->test($ctx->targetClass, $ctx);
    }

    public function matchesRuntime(RuntimeMatchContext $ctx): bool
    {
        if (null !== $this->binding) {
            $ctx->bind($this->binding->name, $ctx->proxy);
        }

        return true;
    }

    /**
     * @param \ReflectionClass<object> $class
     */
    private function test(\ReflectionClass $class, StaticMatchContext $ctx): bool
    {
        if (null === $this->subjectType) {
            return true;
        }
        // The proxy is an instance of each introduced interface and of its parents, like the implemented interfaces below.
        foreach ($ctx->introducedInterfaces as $interface) {
            foreach ([$interface, ...(class_implements($interface) ?: [])] as $name) {
                if ($this->subjectType->matches($name)) {
                    return true;
                }
            }
        }
        if (ProxyStrategy::SUBCLASS === $ctx->strategy) {
            return $this->subjectType->matchesType($class);
        }
        foreach ($class->getInterfaceNames() as $interface) {
            if ($this->subjectType->matches($interface)) {
                return true;
            }
        }

        return false;
    }
}
