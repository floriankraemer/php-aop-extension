<?php

declare(strict_types=1);

namespace AopE2E\Parents;

// Compiled only after Weaver::declareParents() + enable(), so the engine weaves the introduction into it.
final class Invoice
{
    public function total(): int
    {
        return 42;
    }
}
