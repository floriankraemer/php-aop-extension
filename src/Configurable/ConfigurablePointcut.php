<?php

declare(strict_types=1);

namespace AOP\Configurable;

use AOP\Exception\AspectDefinitionException;
use AOP\JoinPoint\JoinPointKind;
use AOP\Pointcut\PointcutInterface;
use AOP\Pointcut\RuntimeMatchContext;
use AOP\Pointcut\StaticMatchContext;

/**
 * Constructor executions (and initializations of constructor-less classes) of classes with `#[Configurable]`.
 *
 * It is deliberately not a FieldInterceptionAwareInterface: classes with a constructor are served by their
 * constructor execution on every backend, so a Weaver with `configurable()` must enable under `woven`. Only a
 * constructor-less class needs `initialization` join points, and whether a class has a constructor is unknown until
 * the class is looked at. The woven backend never asks about the creation of such a class, so the one place the
 * problem is visible is when the engine asks about any other join point of it: then this pointcut throws instead of
 * leaving the class silently unconfigured. A constructor-less class without any method is not noticed.
 */
final class ConfigurablePointcut implements PointcutInterface
{
    /**
     * @param (\Closure(): bool)|null $fieldInterceptionAvailable whether `initialization` join points exist on the active backend; null: assume so
     */
    public function __construct(private readonly ?\Closure $fieldInterceptionAvailable = null)
    {
    }

    /**
     * @throws AspectDefinitionException when a constructor-less `#[Configurable]` class cannot be configured on this backend
     */
    public function matchesClass(?\ReflectionClass $class, StaticMatchContext $ctx): bool
    {
        if (null === $class || null === ConfigurableAspect::attributeFor($class)) {
            return false;
        }
        if (JoinPointKind::CONSTRUCTOR_EXECUTION === $ctx->kind || JoinPointKind::INITIALIZATION === $ctx->kind) {
            return true;
        }
        if (null === $class->getConstructor() && !$class->isAbstract() && !$class->isInterface() && null !== $this->fieldInterceptionAvailable && !($this->fieldInterceptionAvailable)()) {
            throw new AspectDefinitionException(sprintf('Class "%s" is #[Configurable] and has no constructor, so it can only be configured through an initialization join point, which the active interception backend (woven) cannot intercept. Give it a constructor, or set aop.interception=hook (or aop.interception=auto without the opcache JIT) in php.ini.', $class->getName()));
        }

        return false;
    }

    public function matchesMethod(?\ReflectionFunctionAbstract $function, StaticMatchContext $ctx): bool
    {
        return $this->matchesClass($ctx->targetClass, $ctx);
    }

    public function isRuntime(): bool
    {
        return false;
    }

    public function matchesRuntime(RuntimeMatchContext $ctx): bool
    {
        return true;
    }

    public function bindings(): array
    {
        return [];
    }

    public function isCallerSensitive(): bool
    {
        return false;
    }
}
