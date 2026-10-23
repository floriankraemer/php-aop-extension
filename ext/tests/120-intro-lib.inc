<?php
namespace Lib;

const GREETING = 'hello';
enum Tone: string { case Warm = 'warm'; case Cold = 'cold'; }

interface Greeter
{
    const SUFFIX = '!';
    public function greet(string $name, string $suffix = self::SUFFIX, Tone $tone = Tone::Warm, string $word = GREETING): string;
    public function collect(array &$into, int|string ...$items): void;
    public function self(?self $other = null): ?self;
    public static function describe(): string;
}

class DefaultGreeter implements Greeter
{
    public static int $created = 0;
    private int $calls = 0;
    public function __construct() { self::$created++; }
    public function greet(string $name, string $suffix = self::SUFFIX, Tone $tone = Tone::Warm, string $word = GREETING): string
    {
        return sprintf('%s %s%s (%s, call %d)', $word, $name, $suffix, $tone->value, ++$this->calls);
    }
    public function collect(array &$into, int|string ...$items): void { array_push($into, ...$items); }
    public function self(?Greeter $other = null): ?Greeter { return $other ?? $this; }
    public static function describe(): string { return 'static default'; }
}

interface Counts extends \Countable
{
    public function reset(): static;
}
class DefaultCounts implements Counts
{
    public function count(): int { return 42; }
    public function reset(): static { return $this; }
}
