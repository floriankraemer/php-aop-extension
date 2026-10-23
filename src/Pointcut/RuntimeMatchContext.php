<?php

declare(strict_types=1);

namespace AOP\Pointcut;

use AOP\JoinPoint\JoinPointInterface;

final class RuntimeMatchContext
{
    /**
     * @var array<string, mixed>
     */
    private array $bound = [];

    public function __construct(
        public readonly JoinPointInterface $joinPoint,
        public readonly StaticMatchContext $staticContext,
        public readonly ControlFlowInterface $controlFlow = new EmptyControlFlow(),
    ) {
    }

    /**
     * The executing object (for call kinds the caller's object).
     */
    public function this(): ?object
    {
        return $this->joinPoint->getThis();
    }

    public function target(): ?object
    {
        return $this->joinPoint->getTarget();
    }

    /**
     * @return array<int|string, mixed>
     */
    public function args(): array
    {
        return $this->joinPoint->getArgs();
    }

    /**
     * Is a `constructor-execution` the object's outermost constructor? True unless the runtime says otherwise by
     * making the join point an OutermostAwareInterface.
     */
    public function isOutermost(): bool
    {
        return !$this->joinPoint instanceof OutermostAwareInterface || $this->joinPoint->isOutermost();
    }

    public function function(): ?\ReflectionFunctionAbstract
    {
        return $this->staticContext->function;
    }

    /**
     * @return \ReflectionClass<object>|null
     */
    public function targetClass(): ?\ReflectionClass
    {
        return $this->staticContext->targetClass;
    }

    /**
     * Called by pointcuts that bind values (args, this, target, @annotation, ...).
     */
    public function bind(string $name, mixed $value): void
    {
        $this->bound[$name] = $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function bound(): array
    {
        return $this->bound;
    }
}
