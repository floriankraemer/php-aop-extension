<?php

declare(strict_types=1);

namespace AOP\Pointcut;

use AOP\Exception\BindingException;

final class OrPointcut implements PointcutInterface
{
    /**
     * @throws BindingException when the branches bind different names: whichever branch matches, every bound advice parameter needs a value
     */
    public function __construct(private readonly PointcutInterface $left, private readonly PointcutInterface $right)
    {
        $leftNames = array_keys($left->bindings());
        $rightNames = array_keys($right->bindings());
        sort($leftNames);
        sort($rightNames);
        if ($leftNames !== $rightNames) {
            throw new BindingException(sprintf('Pointcut binds [%s] in one branch of "||" and [%s] in the other; every branch of "||" must bind the same names.', implode(', ', $leftNames), implode(', ', $rightNames)));
        }
    }

    public function matchesClass(?\ReflectionClass $class, StaticMatchContext $ctx): bool
    {
        return $this->left->matchesClass($class, $ctx) || $this->right->matchesClass($class, $ctx);
    }

    public function matchesMethod(?\ReflectionFunctionAbstract $method, StaticMatchContext $ctx): bool
    {
        return $this->left->matchesMethod($method, $ctx) || $this->right->matchesMethod($method, $ctx);
    }

    public function isRuntime(): bool
    {
        return $this->left->isRuntime() || $this->right->isRuntime();
    }

    public function matchesRuntime(RuntimeMatchContext $ctx): bool
    {
        // A branch whose static part failed must not be rescued by its runtime part, so re-check statics.
        return RuntimeEvaluation::matches($this->left, $ctx) || RuntimeEvaluation::matches($this->right, $ctx);
    }

    public function bindings(): array
    {
        return [...$this->left->bindings(), ...$this->right->bindings()];
    }

    public function isCallerSensitive(): bool
    {
        return $this->left->isCallerSensitive() || $this->right->isCallerSensitive();
    }
}
