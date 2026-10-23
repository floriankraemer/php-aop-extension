<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Targets;

final class Square extends ShapeBase implements ShapeFactoryInterface
{
    public function __construct(public readonly int $size = 1)
    {
    }

    public function scaled(int $factor): static
    {
        return new static($this->size * $factor);
    }

    public function sides(): int
    {
        return 4;
    }
}
