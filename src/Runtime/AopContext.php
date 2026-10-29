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
        $fiber = \Fiber::getCurrent();
        if (null === $fiber) {
            self::$main[] = $joinPoint;

            return;
        }
        self::$fibers ??= new \WeakMap();
        $stack = self::$fibers[$fiber] ?? [];
        $stack[] = $joinPoint;
        self::$fibers[$fiber] = $stack;
    }

    /**
     * @internal called by the runtime dispatcher
     */
    public static function pop(): void
    {
        $fiber = \Fiber::getCurrent();
        if (null === $fiber) {
            array_pop(self::$main);

            return;
        }
        self::$fibers ??= new \WeakMap();
        $stack = self::$fibers[$fiber] ?? [];
        array_pop($stack);
        self::$fibers[$fiber] = $stack;
    }

    /**
     * @return list<JoinPointInterface>
     */
    private static function stack(): array
    {
        $fiber = \Fiber::getCurrent();

        return null === $fiber ? self::$main : (self::$fibers[$fiber] ?? []);
    }
}
