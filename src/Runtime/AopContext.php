<?php

declare(strict_types=1);

namespace AOP\Runtime;

use AOP\JoinPoint\JoinPointInterface;

/**
 * Gives advised code and advice access to the join point being executed, like Spring's AopContext.
 * The stack is kept per Fiber, so a suspended Fiber never disturbs the join points of the code that resumed it.
 */
final class AopContext
{
    /** @var list<JoinPointInterface> join points of the main (non-Fiber) execution */
    private static array $main = [];

    /** @var \WeakMap<object, list<JoinPointInterface>>|null */
    private static ?\WeakMap $fibers = null;

    private function __construct()
    {
    }

    /**
     * The innermost join point currently being executed, or null outside an advised call.
     */
    public static function currentJoinPoint(): ?JoinPointInterface
    {
        $stack = self::stack();

        return $stack[\count($stack) - 1] ?? null;
    }

    /**
     * The executing object of the innermost join point; null outside an advised call and for static code and functions.
     */
    public static function currentObject(): ?object
    {
        return self::currentJoinPoint()?->getThis();
    }

    /**
     * @internal called by the runtime dispatcher
     */
    public static function push(JoinPointInterface $joinPoint): void
    {
        $stack = self::stack();
        $stack[] = $joinPoint;
        self::store($stack);
    }

    /**
     * @internal called by the runtime dispatcher
     */
    public static function pop(): void
    {
        $stack = self::stack();
        array_pop($stack);
        self::store($stack);
    }

    /**
     * @return list<JoinPointInterface>
     */
    private static function stack(): array
    {
        $fiber = \Fiber::getCurrent();

        return null === $fiber ? self::$main : (self::$fibers[$fiber] ?? []);
    }

    /**
     * @param list<JoinPointInterface> $stack
     */
    private static function store(array $stack): void
    {
        $fiber = \Fiber::getCurrent();
        if (null === $fiber) {
            self::$main = $stack;

            return;
        }
        self::$fibers ??= new \WeakMap();
        self::$fibers[$fiber] = $stack;
    }
}
