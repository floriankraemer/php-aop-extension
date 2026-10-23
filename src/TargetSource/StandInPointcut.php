<?php

declare(strict_types=1);

namespace AOP\TargetSource;

use AOP\JoinPoint\JoinPointKind;
use AOP\Pointcut\PointcutInterface;
use AOP\Pointcut\RuntimeMatchContext;
use AOP\Pointcut\StaticMatchContext;

/**
 * @internal matches the instance methods of one stand-in object
 */
final class StandInPointcut implements PointcutInterface
{
    /** @var \WeakReference<object> */
    private readonly \WeakReference $standIn;

    private readonly string $class;

    public function __construct(object $standIn)
    {
        $this->standIn = \WeakReference::create($standIn);
        $this->class = $standIn::class;
    }

    public function matchesClass(?\ReflectionClass $class, StaticMatchContext $ctx): bool
    {
        return JoinPointKind::METHOD_EXECUTION === $ctx->kind && null !== $class && $class->getName() === $this->class;
    }

    public function matchesMethod(?\ReflectionFunctionAbstract $function, StaticMatchContext $ctx): bool
    {
        return $this->matchesClass($ctx->targetClass, $ctx)
            && $function instanceof \ReflectionMethod
            && !$function->isStatic()
            && !in_array(strtolower($function->getName()), ['__construct', '__destruct'], true);
    }

    public function isRuntime(): bool
    {
        return true;
    }

    public function matchesRuntime(RuntimeMatchContext $ctx): bool
    {
        $standIn = $this->standIn->get();

        return null !== $standIn && $ctx->joinPoint->getThis() === $standIn;
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
