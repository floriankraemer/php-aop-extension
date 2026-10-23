<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Targets;

interface BuilderInterface
{
    public function add(string $part): BuilderInterface;

    public function with(string $part): static;

    public function same(): static;

    public function copy(): BuilderInterface;

    public function other(): object;

    public function value(): string;
}
