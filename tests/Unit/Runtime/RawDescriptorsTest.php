<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Runtime;

use AOP\Advisor\Advisor;
use AOP\JoinPoint\FieldSignature;
use AOP\JoinPoint\JoinPointKind;
use AOP\Pointcut\Expression\PointcutExpressionCompiler;
use AOP\Runtime\EngineBridgeInterface;
use AOP\Tests\Fixtures\Pointcut\Callee;
use AOP\Tests\Fixtures\Runtime\Trace;
use AOP\Weaver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Drives the runtime with the raw descriptors the engine sends for kinds the FakeEngine does not produce
 * (field access, handlers), straight through the registered matcher and dispatcher.
 */
final class RawDescriptorsTest extends TestCase
{
    private const ADVISE = 1;

    /** @var callable(array<string, mixed>): int */
    private $matcher;

    /** @var callable(array<string, mixed>, ?object, array<int|string, mixed>, callable): mixed */
    private $dispatcher;

    private function weave(string $expression, Trace $trace): void
    {
        $capture = new class implements EngineBridgeInterface {
            /** @var (callable(array<string, mixed>): int)|null */
            public $matcher;

            /** @var (callable(array<string, mixed>, ?object, array<int|string, mixed>, callable): mixed)|null */
            public $dispatcher;

            public function register(callable $matcher, callable $dispatcher): void
            {
                $this->matcher = $matcher;
                $this->dispatcher = $dispatcher;
            }

            public function unregister(): void
            {
            }

            public function reset(): void
            {
            }

            public function interceptionActive(): bool
            {
                return true;
            }

            public function fieldInterceptionAvailable(): bool
            {
                return true;
            }
        };
        $weaver = new Weaver($capture);
        $weaver->addAdvisor(new Advisor('trace', null, 1, (new PointcutExpressionCompiler())->compile($expression), $trace));
        $weaver->enable();
        \assert(null !== $capture->matcher && null !== $capture->dispatcher);
        $this->matcher = $capture->matcher;
        $this->dispatcher = $capture->dispatcher;
    }

    /**
     * @return array<string, mixed>
     */
    private static function field(string $kind, string $name): array
    {
        return ['kind' => $kind, 'class' => Callee::class, 'scope' => Callee::class, 'name' => $name, 'static' => false, 'internal' => false, 'caller' => null];
    }

    #[Test]
    public function fieldReadsAreMatchedAndAdvisedWithTheirProperty(): void
    {
        $this->weave('get(* '.Callee::class.'::$count)', $trace = new Trace('get'));
        $callee = new Callee();
        $callee->count = 7;
        $descriptor = self::field('field-get', 'count');

        self::assertSame(self::ADVISE, ($this->matcher)($descriptor));
        self::assertSame(0, ($this->matcher)(self::field('field-get', 'label')), 'another property');
        self::assertSame(0, ($this->matcher)(self::field('field-set', 'count')), 'a write is not a read');
        self::assertSame(self::ADVISE, ($this->matcher)(self::field('field-get', '*')), 'the class probe only asks whether the class can have such a join point');

        $result = ($this->dispatcher)($descriptor, $callee, [], static fn (array $a): int => $callee->count);

        self::assertSame(7, $result);
        self::assertSame(JoinPointKind::FIELD_GET, $trace->seen[0]->getKind());
        $signature = $trace->seen[0]->getSignature();
        self::assertInstanceOf(FieldSignature::class, $signature);
        self::assertSame('count', $signature->property?->getName());
        self::assertSame($callee, $trace->seen[0]->getTarget());
    }

    #[Test]
    public function fieldWritesPassTheNewValueAsTheOnlyArgument(): void
    {
        $this->weave('set(* '.Callee::class.'::$count)', $trace = new Trace('set', static fn ($invocation): mixed => $invocation->proceed([42])));
        $callee = new Callee();
        $received = null;

        ($this->dispatcher)(self::field('field-set', 'count') + ['value' => 21], $callee, [], static function (array $a) use (&$received): null {
            $received = $a;

            return null;
        });

        self::assertSame([21], $trace->args[0]);
        self::assertSame([42], $received, 'advice replaced the value the engine finally assigns');
    }

    #[Test]
    public function aWriteWithoutAValueKeyFallsBackToTheFirstRawArgument(): void
    {
        $this->weave('set(* '.Callee::class.'::$count)', $trace = new Trace('set'));

        ($this->dispatcher)(self::field('field-set', 'count'), new Callee(), [5], static fn (array $a): null => null);

        self::assertSame([[5]], $trace->args);
    }

    #[Test]
    public function handlersReceiveTheCaughtExceptionAndHaveNoTarget(): void
    {
        $this->weave('handler('.\RuntimeException::class.')', $trace = new Trace('catch'));
        $exception = new \RuntimeException('boom');
        $descriptor = ['kind' => 'exception-handler', 'class' => Callee::class, 'scope' => Callee::class, 'name' => \RuntimeException::class, 'static' => false, 'internal' => false, 'caller' => null, 'exception' => $exception];

        self::assertSame(self::ADVISE, ($this->matcher)($descriptor));
        self::assertSame(0, ($this->matcher)(['name' => \LogicException::class] + $descriptor));

        ($this->dispatcher)($descriptor, new Callee(), [], static fn (array $a): null => null);

        self::assertSame([[$exception]], $trace->args);
        self::assertSame(JoinPointKind::EXCEPTION_HANDLER, $trace->seen[0]->getKind());
        self::assertNull($trace->seen[0]->getTarget());
        self::assertSame(\RuntimeException::class, $trace->seen[0]->getSignature()->getName());
    }

    #[Test]
    public function anUnknownKindIsNeverAdvised(): void
    {
        $this->weave('execution(* *(..))', new Trace('x'));

        self::assertSame(0, ($this->matcher)(['kind' => 'no-such-kind', 'class' => Callee::class, 'name' => 'find']));
    }
}
