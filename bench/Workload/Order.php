<?php

declare(strict_types=1);

namespace AopBench\Workload;

final class OrderLine
{
    public function __construct(
        private readonly string $sku,
        private readonly int $quantity,
        private readonly int $unitCents,
    ) {
    }

    public function sku(): string
    {
        return $this->sku;
    }

    public function totalCents(): int
    {
        return $this->quantity * $this->unitCents;
    }
}

final class Order
{
    /** @var list<OrderLine> */
    private array $lines = [];

    public function add(OrderLine $line): void
    {
        $this->lines[] = $line;
    }

    public function totalCents(): int
    {
        $sum = 0;
        foreach ($this->lines as $line) {
            $sum += $line->totalCents();
        }

        return $sum;
    }

    public function summary(): string
    {
        $parts = [];
        foreach ($this->lines as $line) {
            $parts[] = $line->sku().'='.$line->totalCents();
        }

        return implode(',', $parts);
    }
}
