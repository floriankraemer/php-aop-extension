<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Pointcut;

use AOP\JoinPoint\JoinPointInterface;

/**
 * Static methods for if(): the conditions used by the tests, and invalid ones.
 */
final class Conditions
{
    /** @var list<string> */
    public static array $seen = [];

    public static function always(): bool
    {
        return true;
    }

    public static function never(): bool
    {
        return false;
    }

    public static function forFind(JoinPointInterface $jp): bool
    {
        self::$seen[] = $jp->getSignature()->getName();

        return 'find' === $jp->getSignature()->getName();
    }

    private static function hidden(): bool // @phpstan-ignore method.unused
    {
        return true;
    }

    /** @return bool */
    public static function untypedTruthy()
    {
        return true;
    }

    public static function untypedWrong() // @phpstan-ignore missingType.return
    {
        return 'yes';
    }

    public function instance(): bool
    {
        return true;
    }

    public static function wrongReturn(): int
    {
        return 1;
    }

    public static function nullableReturn(): ?bool
    {
        return [] === self::$seen ? null : true;
    }

    public static function twoParameters(JoinPointInterface $jp, int $x): bool
    {
        return true;
    }

    public static function wrongParameter(string $name): bool
    {
        return true;
    }

    // @phpstan-ignore missingType.parameter (untyped parameter is the point of this fixture)
    public static function untypedParameter($jp): bool
    {
        return true;
    }

    public static function unionParameter(JoinPointInterface|string $jp): bool
    {
        return true;
    }
}
