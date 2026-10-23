<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Targets;

use AOP\Tests\Fixtures\Log;

/**
 * Fluent (`add`, `same`) and immutable (`with`, `copy`) methods behind an interface proxy.
 */
final class Builder implements BuilderInterface
{
    /** @var list<string> */
    private array $parts = [];

    public function add(string $part): BuilderInterface
    {
        Log::add('add '.$part);
        $this->parts[] = $part;

        return $this;
    }

    public function with(string $part): static
    {
        Log::add('with '.$part);
        $copy = clone $this;
        $copy->parts[] = $part;

        return $copy;
    }

    public function same(): static
    {
        return $this;
    }

    public function copy(): self
    {
        return clone $this;
    }

    public function other(): object
    {
        return new \stdClass();
    }

    public function value(): string
    {
        return implode(',', $this->parts);
    }
}
