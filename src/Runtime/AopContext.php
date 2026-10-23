<?php

declare(strict_types=1);

namespace AOP\Runtime;

/**
 * Gives a target access to its own proxy, like Spring's AopContext.currentProxy().
 *
 * With an interface proxy `$this` is the plain target, so `$this->other()` bypasses advice;
 * `AopContext::currentProxy()->other()` goes through it.
 */
final class AopContext
{
    /** @var list<object> */
    private static array $proxies = [];

    private function __construct()
    {
    }

    /**
     * The proxy whose advised method is currently executing, or null outside an advised call.
     */
    public static function currentProxy(): ?object
    {
        return self::$proxies[\count(self::$proxies) - 1] ?? null;
    }

    /**
     * @internal called by AopRuntime
     */
    public static function push(object $proxy): void
    {
        self::$proxies[] = $proxy;
    }

    /**
     * @internal called by AopRuntime
     */
    public static function pop(): void
    {
        array_pop(self::$proxies);
    }
}
