<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Runtime;

use AOP\Runtime\AopContext;
use AOP\Runtime\ArgumentNormalizer;
use AOP\Runtime\ControlFlowStack;
use AOP\Tests\Fixtures\Jp;
use AOP\Tests\Fixtures\Service\Greeter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SupportClassesTest extends TestCase
{
    /**
     * @param array<int|string, mixed> $raw
     *
     * @return array<int|string, mixed>
     */
    private static function normalize(callable $function, array $raw): array
    {
        return ArgumentNormalizer::normalize((new \ReflectionFunction(\Closure::fromCallable($function)))->getParameters(), $raw);
    }

    #[Test]
    public function normalizerFillsTrailingDefaultsOnly(): void
    {
        $fn = static fn (int $a, int $b = 2, int $c = 3): int => $a;

        self::assertSame([1, 2, 3], self::normalize($fn, [1]));
        self::assertSame([1, 9, 3], self::normalize($fn, [1, 9]));
    }

    #[Test]
    public function normalizerKeepsTheVariadicTailWithItsKeys(): void
    {
        $fn = static fn (string $a = 'x', string ...$rest): string => $a;

        self::assertSame(['x'], self::normalize($fn, []));
        self::assertSame(['a', 'b', 'c', 'n' => 'd'], self::normalize($fn, ['a', 'b', 'c', 'n' => 'd']));
    }

    #[Test]
    public function normalizerKeepsReferences(): void
    {
        $fn = static function (int &$a, int $b = 0): void {
        };
        $value = 1;

        $out = self::normalize($fn, [&$value]);
        $out[0] = 5;

        self::assertSame(5, $value);
        self::assertSame(0, $out[1]);
    }

    #[Test]
    public function normalizerPlacesNamedDeclaredArgumentsAndStopsAtGapsWithoutDefaults(): void
    {
        $fn = static fn (int $a, int $b, int $c = 3): int => $a;

        self::assertSame([1, 2, 3], self::normalize($fn, [1, 'b' => 2]));
        self::assertSame([1], self::normalize($fn, [1]), 'a required parameter without a value leaves a gap; the original reports it');
    }

    #[Test]
    public function aopContextKeepsOneStackPerFiber(): void
    {
        $outer = Jp::invocation(new Greeter(), 'greet', ['o']);
        $inner = Jp::invocation(new Greeter(), 'fail');
        AopContext::push($outer);
        try {
            $fiber = new \Fiber(static function () use ($inner): string {
                $before = AopContext::currentJoinPoint();
                AopContext::push($inner);
                \Fiber::suspend($before);
                $during = AopContext::currentJoinPoint() === $inner ? 'inner' : 'other';
                AopContext::pop();

                return $during;
            });
            self::assertNull($fiber->start(), 'a Fiber does not see the outer join point');
            self::assertSame($outer, AopContext::currentJoinPoint(), 'a suspended Fiber does not disturb the outer stack');
            $fiber->resume();
            self::assertSame('inner', $fiber->getReturn());
        } finally {
            AopContext::pop();
        }
    }

    #[Test]
    public function controlFlowStackTracksFramesAndTokens(): void
    {
        $flow = new ControlFlowStack();
        $jp = Jp::invocation(new Greeter(), 'greet', ['o']);

        $flow->enter($jp);
        $flow->pushToken(Greeter::class);
        self::assertSame([$jp], $flow->stack());
        self::assertNotNull($flow->activeToken(Greeter::class, false));
        self::assertNull($flow->activeToken(Greeter::class, true), 'nothing runs below the entry yet');

        $flow->enter($jp);
        self::assertNotNull($flow->activeToken(Greeter::class, true));
        $flow->enter($jp, false);
        $flow->leave(false);
        $flow->leave();
        $flow->popToken(Greeter::class);
        $flow->leave();

        self::assertSame([], $flow->stack());
        self::assertNull($flow->activeToken(Greeter::class, false));
    }

    #[Test]
    public function normalizerStopsFillingDefaultsAfterOneThatCannotBeEvaluated(): void
    {
        // The default refers to a constant that does not exist, so it cannot be filled; later defaults must not shift into its place.
        // @phpstan-ignore constant.notFound
        $fn = static fn (int $a, int $b = \AOP_TESTS_UNDEFINED_CONSTANT, int $c = 3): int => $a;

        self::assertSame([1], self::normalize($fn, [1]));
        self::assertSame([1, 'c' => 9], self::normalize($fn, [1, 'c' => 9]));
    }
}
