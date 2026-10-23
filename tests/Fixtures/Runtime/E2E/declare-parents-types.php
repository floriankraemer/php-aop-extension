<?php

declare(strict_types=1);

namespace AopE2E\Parents;

interface Labelled
{
    public function label(): string;
}

final class DefaultLabel implements Labelled
{
    public function label(): string
    {
        return 'default label';
    }
}
