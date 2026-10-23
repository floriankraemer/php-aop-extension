<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Runtime;

use AOP\Tests\Fixtures\Log;

/**
 * Members of every shape the engine can advise.
 */
class TargetService
{
    public int $constructed = 0;

    public function __construct(public string $label = 'default')
    {
        ++$this->constructed;
    }

    public function pub(string $name): string
    {
        Log::add('pub');

        return 'pub:'.$name;
    }

    public function viaSelf(string $name): string
    {
        return $this->hidden($name);
    }

    private function hidden(string $name): string
    {
        Log::add('hidden');

        return 'hidden:'.$name;
    }

    protected function guarded(): string
    {
        return 'guarded';
    }

    public function callGuarded(): string
    {
        return $this->guarded();
    }

    public static function make(string $what = 'thing'): string
    {
        return 'made:'.$what;
    }

    final public function sealed(): string
    {
        return 'sealed';
    }

    public function defaults(int $a, int $b = 5, string $c = 'c'): string
    {
        return $a.'/'.$b.'/'.$c;
    }

    public function join(string $separator = ',', string ...$parts): string
    {
        return implode($separator, $parts);
    }

    public function bump(int &$counter): void
    {
        ++$counter;
    }

    public function count(): int
    {
        return 3;
    }

    public function self(): static
    {
        return $this;
    }

    public function fail(): never
    {
        throw new \LogicException('failed');
    }
}
