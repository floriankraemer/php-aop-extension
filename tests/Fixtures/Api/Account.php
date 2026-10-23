<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Api;

use AOP\Tests\Fixtures\Log;

class Account
{
    public function getBalance(): int
    {
        return 100;
    }

    public function setBalance(int $balance): void
    {
        Log::add('set:'.$balance);
    }

    public function deposit(int $amount, string $note = ''): int
    {
        return 100 + $amount;
    }

    public function fail(): never
    {
        throw new \InvalidArgumentException('bad');
    }

    public function failRuntime(): never
    {
        throw new \UnexpectedValueException('unexpected');
    }

    public function failLogic(): never
    {
        throw new \LogicException('logic');
    }

    public function failOther(): never
    {
        throw new \DomainException('domain');
    }

    public function fluent(): static
    {
        return $this;
    }

    protected function secret(): string
    {
        return 'secret';
    }

    public static function create(): self
    {
        return new self();
    }
}
