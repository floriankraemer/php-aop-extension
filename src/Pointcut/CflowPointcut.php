<?php

declare(strict_types=1);

namespace AOP\Pointcut;

/**
 * cflow(p) / cflowbelow(p): some join point on the current control flow (RuntimeMatchContext::$controlFlow->stack(),
 * outermost first, the current join point last) matches p, statically and at runtime. cflowbelow() leaves out the
 * current join point itself.
 *
 * Always a runtime pointcut. The bindings of p propagate: the innermost matching stack entry provides the values.
 * Not caller-sensitive: the entries are matched with their own, recorded caller.
 */
final class CflowPointcut implements PointcutInterface, ControlFlowAwareInterface, FieldInterceptionAwareInterface
{
    public function __construct(private readonly PointcutInterface $inner, private readonly bool $below = false)
    {
    }

    public function inner(): PointcutInterface
    {
        return $this->inner;
    }

    public function isBelow(): bool
    {
        return $this->below;
    }

    public function matchesClass(?\ReflectionClass $class, StaticMatchContext $ctx): bool
    {
        return true;
    }

    public function matchesMethod(?\ReflectionFunctionAbstract $method, StaticMatchContext $ctx): bool
    {
        return true;
    }

    public function isRuntime(): bool
    {
        return true;
    }

    public function matchesRuntime(RuntimeMatchContext $ctx): bool
    {
        $stack = $ctx->controlFlow->stack();
        // innermost first
        for ($i = count($stack) - ($this->below ? 2 : 1); $i >= 0; --$i) {
            $entry = $stack[$i];
            $static = $entry === $ctx->joinPoint ? $ctx->staticContext : StaticContextFactory::fromJoinPoint($entry);
            if (null === $static) {
                continue;
            }
            $entryContext = new RuntimeMatchContext($entry, $static, new PrefixControlFlow(array_slice($stack, 0, $i + 1)));
            if (RuntimeEvaluation::matches($this->inner, $entryContext)) {
                foreach ($entryContext->bound() as $name => $value) {
                    $ctx->bind($name, $value);
                }

                return true;
            }
        }

        return false;
    }

    public function bindings(): array
    {
        return $this->inner->bindings();
    }

    public function isCallerSensitive(): bool
    {
        return false;
    }

    public function cflowPointcuts(): array
    {
        return [$this->inner, ...ControlFlowPointcuts::of($this->inner)];
    }

    public function requiresFieldInterception(): bool
    {
        return FieldInterception::requiredBy($this->inner);
    }
}
