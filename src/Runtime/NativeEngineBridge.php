<?php

declare(strict_types=1);

namespace AOP\Runtime;

use AOP\Exception\AspectDefinitionException;

/**
 * Talks to the compiled extension (`\AOP\Engine`). Constructing it works without the extension, so aspects can be
 * read and configured anywhere; registering with the engine then fails with a clear message.
 */
final class NativeEngineBridge implements IntroducingEngineInterface
{
    public function register(callable $matcher, callable $dispatcher): void
    {
        self::engine()::register($matcher, $dispatcher);
    }

    public function unregister(): void
    {
        self::engine()::unregister();
    }

    public function reset(): void
    {
        self::engine()::reset();
    }

    public function setIntroductions(array $rules): void
    {
        self::engine()::setIntroductions($rules);
    }

    public function isRegistered(): bool
    {
        return self::available() && \AOP\Engine::isRegistered();
    }

    public function fieldInterceptionAvailable(): bool
    {
        return self::available() && \AOP\Engine::fieldInterceptionAvailable();
    }

    public static function available(): bool
    {
        return class_exists(\AOP\Engine::class, false);
    }

    /**
     * @return class-string<\AOP\Engine>
     */
    private static function engine(): string
    {
        if (!self::available()) {
            throw new AspectDefinitionException('The "aop" PHP extension is not loaded, so aspects cannot be woven. Install and enable ext-aop.');
        }

        return \AOP\Engine::class;
    }
}
