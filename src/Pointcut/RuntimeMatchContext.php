<?php

declare(strict_types=1);

namespace AOP\Pointcut;

use AOP\Proxy\AdvisedInterface;

final class RuntimeMatchContext
{
    /**
     * @var array<string, mixed>
     */
    private array $bound = [];

    /**
     * @param array<int|string, mixed> $args
     * @param StaticMatchContext|null  $staticContext context used at weave time; lets combinators re-evaluate static parts at runtime
     */
    public function __construct(
        public readonly object $proxy,
        public readonly object $target,
        public readonly \ReflectionMethod $method,
        public readonly array $args,
        public readonly ?StaticMatchContext $staticContext = null,
    ) {
    }

    /**
     * The advised class: the weave-time target class, else the target's class with generated proxy classes
     * (and subclasses of them, e.g. lazy ghosts) skipped, since attributes are not inherited.
     *
     * @return \ReflectionClass<object>
     */
    public function targetClass(): \ReflectionClass
    {
        if (null !== $this->staticContext) {
            return $this->staticContext->targetClass;
        }
        $class = $this->target::class;
        while (is_a($class, AdvisedInterface::class, true) && false !== ($parent = get_parent_class($class))) {
            $class = $parent;
        }

        return new \ReflectionClass($class);
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
