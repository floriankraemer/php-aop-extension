<?php

declare(strict_types=1);

namespace AOP\Tests\Support;

use AOP\Runtime\IntroducingEngineInterface;

/**
 * FakeEngine plus the recording of introduction rules (FakeEngine is final, so this wraps it).
 */
final class RecordingEngine implements IntroducingEngineInterface
{
    /** @var list<list<array{typePattern: string, interface: class-string, defaultImpl: class-string}>> */
    public array $introductions = [];

    public function __construct(public readonly FakeEngine $fake = new FakeEngine())
    {
    }

    public function register(callable $matcher, callable $dispatcher): void
    {
        $this->fake->register($matcher, $dispatcher);
    }

    public function unregister(): void
    {
        $this->fake->unregister();
    }

    public function reset(): void
    {
        $this->fake->reset();
    }

    public function interceptionActive(): bool
    {
        return $this->fake->interceptionActive();
    }

    public function fieldInterceptionAvailable(): bool
    {
        return $this->fake->fieldInterceptionAvailable();
    }

    public function setIntroductions(array $rules): void
    {
        $this->introductions[] = $rules;
    }
}
