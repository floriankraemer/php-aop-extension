<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Runtime;

enum Mode: string
{
    case On = 'on';

    public function label(): string
    {
        return $this->value;
    }
}
