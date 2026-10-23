<?php

declare(strict_types=1);

namespace AOP\Runtime;

use AOP\JoinPoint\JoinPointInterface;

/**
 * Gives advised code and advice access to the join point being executed, like Spring's AopContext.
 */
final class AopContext
{
    /** @var list<JoinPointInterface> */
    private static array $joinPoints = [];

    private function __construct()
    {
    }

    /**
     * The innermost join point currently being executed, or null outside an advised call.
     */
    public static function currentJoinPoint(): ?JoinPointInterface
    {
        return self::$joinPoints[\count(self::$joinPoints) - 1] ?? null;
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
        self::$joinPoints[] = $joinPoint;
    }

    /**
     * @internal called by the runtime dispatcher
     */
    public static function pop(): void
    {
        array_pop(self::$joinPoints);
    }
}
