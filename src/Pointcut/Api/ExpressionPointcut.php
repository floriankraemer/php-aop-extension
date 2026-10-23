<?php

declare(strict_types=1);

namespace AOP\Pointcut\Api;

use AOP\Exception\PointcutSyntaxException;
use AOP\Pointcut\ControlFlowAwareInterface;
use AOP\Pointcut\ControlFlowPointcuts;
use AOP\Pointcut\Expression\NamedPointcutResolverInterface;
use AOP\Pointcut\Expression\PointcutExpressionCompiler;
use AOP\Pointcut\PointcutInterface;
use AOP\Pointcut\RuntimeMatchContext;
use AOP\Pointcut\StaticMatchContext;

/**
 * A pointcut written as an expression string, e.g. `execution(* App\Service\**::*(..)) && !within(App\Legacy\*)`
 * (Spring's AspectJExpressionPointcut). Compiled once, eagerly.
 */
final class ExpressionPointcut implements PointcutInterface, ControlFlowAwareInterface
{
    private readonly PointcutInterface $compiled;

    /**
     * @param array<int|string, string|null> $bindingCandidates names an advice may bind, see PointcutExpressionCompiler::compile()
     *
     * @throws PointcutSyntaxException
     */
    public function __construct(
        private readonly string $expression,
        ?PointcutExpressionCompiler $compiler = null,
        array $bindingCandidates = [],
        ?NamedPointcutResolverInterface $resolver = null,
        string $declaringAspect = '',
    ) {
        $this->compiled = ($compiler ?? new PointcutExpressionCompiler())->compile($expression, $bindingCandidates, $declaringAspect, $resolver);
    }

    public function getExpression(): string
    {
        return $this->expression;
    }

    public function matchesClass(?\ReflectionClass $class, StaticMatchContext $ctx): bool
    {
        return $this->compiled->matchesClass($class, $ctx);
    }

    public function matchesMethod(?\ReflectionFunctionAbstract $function, StaticMatchContext $ctx): bool
    {
        return $this->compiled->matchesMethod($function, $ctx);
    }

    public function isRuntime(): bool
    {
        return $this->compiled->isRuntime();
    }

    public function matchesRuntime(RuntimeMatchContext $ctx): bool
    {
        return $this->compiled->matchesRuntime($ctx);
    }

    public function bindings(): array
    {
        return $this->compiled->bindings();
    }

    public function isCallerSensitive(): bool
    {
        return $this->compiled->isCallerSensitive();
    }

    public function cflowPointcuts(): array
    {
        return ControlFlowPointcuts::of($this->compiled);
    }
}
