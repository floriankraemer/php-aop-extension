<?php

declare(strict_types=1);

namespace AOP\Pointcut\Api;

use AOP\JoinPoint\JoinPointKind;
use AOP\Pointcut\ControlFlowAwareInterface;
use AOP\Pointcut\PointcutInterface;
use AOP\Pointcut\RuntimeMatchContext;
use AOP\Pointcut\StaticMatchContext;

/**
 * Matches while the current Fiber is inside a call of a class (and optionally a method name), Spring's
 * ControlFlowPointcut. It reads the join points that are active right now from the runtime's control flow
 * (`RuntimeMatchContext::$controlFlow->stack()`), so only advised join points show up in it.
 *
 * The advised join point itself counts as part of the flow unless `$below` is true (AspectJ's `cflowbelow`).
 * The class matches the declaring type of an active join point, or any class its executing object is an
 * instance of. The method name may contain `*`.
 *
 * Like every Spring pointcut it advises method and function executions only (never call join points; see `$kinds`),
 * and it is control-flow aware like `cflow()`: it names the join points that make up the flow
 * (`cflowPointcuts()`), so the runtime tracks them on the stack even when no other advisor touches them.
 */
final class ControlFlowPointcut implements PointcutInterface, ControlFlowAwareInterface
{
    /** @var list<JoinPointKind> */
    private readonly array $kinds;

    private int $evaluations = 0;

    private readonly ?string $methodRegex;

    /**
     * @param class-string             $class
     * @param list<JoinPointKind>|null $kinds join point kinds the advice applies to (default: method and function executions)
     */
    public function __construct(
        private readonly string $class,
        ?string $methodName = null,
        private readonly bool $below = false,
        ?array $kinds = null,
    ) {
        $this->kinds = $kinds ?? [JoinPointKind::METHOD_EXECUTION, JoinPointKind::FUNCTION_EXECUTION];
        $this->methodRegex = null === $methodName ? null : '/^'.str_replace('\*', '.*', preg_quote($methodName, '/')).'$/si';
    }

    /**
     * How often the runtime part was evaluated (a counter for tests, as in Spring).
     */
    public function getEvaluations(): int
    {
        return $this->evaluations;
    }

    public function matchesClass(?\ReflectionClass $class, StaticMatchContext $ctx): bool
    {
        return in_array($ctx->kind, $this->kinds, true);
    }

    public function matchesMethod(?\ReflectionFunctionAbstract $function, StaticMatchContext $ctx): bool
    {
        return in_array($ctx->kind, $this->kinds, true);
    }

    /**
     * The join points that make up the flow: executions of `$class` (or a subtype) whose method name matches. The
     * runtime tracks them so they show up in the control flow stack.
     *
     * @return list<PointcutInterface>
     */
    public function cflowPointcuts(): array
    {
        return [new ControlFlowEntryPointcut($this->class, $this->methodRegex)];
    }

    public function isRuntime(): bool
    {
        return true;
    }

    public function matchesRuntime(RuntimeMatchContext $ctx): bool
    {
        ++$this->evaluations;
        $stack = $ctx->controlFlow->stack();
        if ($this->below) {
            array_pop($stack);
        }
        foreach ($stack as $frame) {
            if (null !== $this->methodRegex && 1 !== preg_match($this->methodRegex, $frame->getSignature()->getName())) {
                continue;
            }
            $declaring = $frame->getSignature()->getDeclaringTypeName();
            if ((null !== $declaring && 0 === strcasecmp(ltrim($declaring, '\\'), ltrim($this->class, '\\'))) || $frame->getThis() instanceof $this->class || $frame->getTarget() instanceof $this->class) {
                return true;
            }
        }

        return false;
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
