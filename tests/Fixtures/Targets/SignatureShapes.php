<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Targets;

use AOP\Tests\Fixtures\Log;

const NS_LIMIT = 7;

/**
 * Every signature shape a subclass proxy must reproduce.
 */
class SignatureShapes
{
    public const LIMIT = 10;
    private const SECRET = 'hidden';

    public function __construct(public readonly string $label = 'shapes')
    {
    }

    public function nullable(?\Countable $c): ?string
    {
        Log::add('nullable');

        return null === $c ? null : (string) count($c);
    }

    public function union(int|string $v): int|string
    {
        Log::add('union');

        return $v;
    }

    /**
     * @param \Countable&\ArrayAccess<int, int> $v
     */
    public function intersection(\Countable&\ArrayAccess $v): int
    {
        Log::add('intersection');

        return count($v);
    }

    /**
     * @param (\Countable&\ArrayAccess<int, int>)|null $v
     */
    public function dnf((\Countable&\ArrayAccess)|null $v): ?int
    {
        Log::add('dnf');

        return null === $v ? null : count($v);
    }

    public function nothing(string $s): void
    {
        Log::add('nothing:'.$s);
    }

    public function explode(string $message): never
    {
        Log::add('explode');

        throw new \RuntimeException($message);
    }

    /**
     * @param array<string, mixed>|null $list
     */
    public function defaults(
        int $classConst = self::LIMIT,
        string $privateConst = self::SECRET,
        int $globalConst = PHP_INT_SIZE,
        int $namespacedConst = NS_LIMIT,
        Suit $enumCase = Suit::Hearts,
        ?array $list = ['a' => 1, 'b' => [2]],
        float $float = 1.5,
        ?string $null = null,
    ): string {
        Log::add('defaults');

        return implode(',', [$classConst, $privateConst, $globalConst, $namespacedConst, $enumCase->value, json_encode($list), $float, var_export($null, true)]);
    }

    public function variadic(string $first, int ...$rest): string
    {
        Log::add('variadic');

        return $first.':'.json_encode($rest);
    }

    /**
     * Deliberately without native types.
     *
     * @param string $anything
     * @param int    $other
     *
     * @return array{string, int}
     */
    public function untyped($anything, $other = 3)
    {
        Log::add('untyped');

        return [$anything, $other];
    }

    public function notAdvised(): string
    {
        Log::add('notAdvised');

        return 'plain';
    }

    protected function guarded(): string
    {
        return 'guarded';
    }

    public function callGuarded(): string
    {
        return $this->guarded();
    }
}
