<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Targets;

enum Suit: string
{
    case Hearts = 'h';
    case Spades = 's';

    public function label(): string
    {
        return ucfirst($this->name);
    }
}
