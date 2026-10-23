<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\TargetSource;

use AOP\TargetSource\FiberLocalTargetSource;
use AOP\TargetSource\HotSwappableTargetSource;
use AOP\TargetSource\PoolExhaustedException;
use AOP\TargetSource\PoolingTargetSource;
use AOP\TargetSource\PrototypeTargetSource;
use AOP\TargetSource\SingletonTargetSource;
use AOP\TargetSource\TargetSourceException;
use AOP\Tests\Fixtures\Api\Customer;
use AOP\Tests\Fixtures\Api\Pooled;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TargetSourcesTest extends TestCase
{
    protected function setUp(): void
    {
        Pooled::resetCounter();
    }

    #[Test]
    public function singletonAlwaysReturnsTheSameObject(): void
    {
        $pooled = new Pooled();
        $source = new SingletonTargetSource($pooled);

        self::assertTrue($source->isStatic());
        self::assertSame(Pooled::class, $source->getTargetClass());
        self::assertSame($pooled, $source->getTarget());
        $source->releaseTarget($pooled);
        self::assertSame($pooled, $source->getTarget());
    }

    #[Test]
    public function hotSwappableSwapsAndReturnsTheOldTarget(): void
    {
        $first = new Pooled();
        $second = new Pooled();
        $source = new HotSwappableTargetSource($first);

        self::assertFalse($source->isStatic());
        self::assertSame($first, $source->getTarget());
        self::assertSame($first, $source->swap($second));
        self::assertSame($second, $source->getTarget());
        self::assertSame($second, $source->swap($first));
        self::assertSame(Pooled::class, $source->getTargetClass());
        $source->releaseTarget($first);
    }

    #[Test]
    public function hotSwappableRejectsTargetsOfAnotherType(): void
    {
        $source = new HotSwappableTargetSource(new Pooled());

        $this->expectException(TargetSourceException::class);
        $this->expectExceptionMessage('Cannot use '.Customer::class.' as target');

        $source->swap(new Customer());
    }

    #[Test]
    public function hotSwappableCanHoldAnInterfaceType(): void
    {
        $source = new HotSwappableTargetSource(new Customer(), \AOP\Tests\Fixtures\Api\Describable::class);

        $source->swap(new \AOP\Tests\Fixtures\Api\DescribableImpl());

        self::assertSame(\AOP\Tests\Fixtures\Api\Describable::class, $source->getTargetClass());
        self::assertInstanceOf(\AOP\Tests\Fixtures\Api\DescribableImpl::class, $source->getTarget());
    }

    #[Test]
    public function hotSwappableChecksTheInitialTarget(): void
    {
        $this->expectException(TargetSourceException::class);

        new HotSwappableTargetSource(new Customer(), Pooled::class);
    }

    #[Test]
    public function prototypeCreatesANewTargetEveryTime(): void
    {
        $source = new PrototypeTargetSource(Pooled::class, static fn (): Pooled => new Pooled());

        $a = $source->getTarget();
        $b = $source->getTarget();
        $source->releaseTarget($a);

        self::assertFalse($source->isStatic());
        self::assertNotSame($a, $b);
        self::assertSame(2, $source->getCreatedCount());
        self::assertSame(Pooled::class, $source->getTargetClass());
    }

    #[Test]
    public function prototypeChecksTheFactoryResult(): void
    {
        $source = new PrototypeTargetSource(Pooled::class, static fn (): Customer => new Customer());

        $this->expectException(TargetSourceException::class);
        $this->expectExceptionMessage('The target factory returned '.Customer::class.', expected an instance of '.Pooled::class);

        $source->getTarget();
    }

    #[Test]
    public function poolReusesReleasedTargets(): void
    {
        $source = new PoolingTargetSource(Pooled::class, static fn (): Pooled => new Pooled(), 2);

        $a = $source->getTarget();
        $source->releaseTarget($a);
        $b = $source->getTarget();

        self::assertSame($a, $b);
        self::assertFalse($source->isStatic());
        self::assertSame(Pooled::class, $source->getTargetClass());
    }

    #[Test]
    public function poolThrowsWhenExhaustedAndRecoversOnRelease(): void
    {
        $source = new PoolingTargetSource(Pooled::class, static fn (): Pooled => new Pooled(), 2);
        $a = $source->getTarget();
        $b = $source->getTarget();

        try {
            $source->getTarget();
            self::fail('expected exhaustion');
        } catch (PoolExhaustedException $e) {
            self::assertStringContainsString('all 2 targets are in use', $e->getMessage());
        }

        $source->releaseTarget($b);
        self::assertSame($b, $source->getTarget());
        self::assertNotSame($a, $b);
    }

    #[Test]
    public function poolTracksStatistics(): void
    {
        $source = new PoolingTargetSource(Pooled::class, static fn (): Pooled => new Pooled(), 2);
        $initial = $source->stats();
        self::assertSame([2, 0, 0, 0, 0, 0, 0], [$initial->maxSize, $initial->active, $initial->idle, $initial->created, $initial->borrowed, $initial->released, $initial->exhausted]);

        $a = $source->getTarget();
        $b = $source->getTarget();
        try {
            $source->getTarget();
        } catch (PoolExhaustedException) {
        }
        $source->releaseTarget($a);
        $stats = $source->stats();

        self::assertSame([2, 1, 1, 2, 2, 1, 1], [$stats->maxSize, $stats->active, $stats->idle, $stats->created, $stats->borrowed, $stats->released, $stats->exhausted]);
        $source->releaseTarget($b);
        self::assertSame(2, $source->stats()->idle);
    }

    #[Test]
    public function poolRejectsForeignAndDoubleReleases(): void
    {
        $source = new PoolingTargetSource(Pooled::class, static fn (): Pooled => new Pooled(), 1);
        $a = $source->getTarget();
        $source->releaseTarget($a);

        $this->expectException(TargetSourceException::class);
        $this->expectExceptionMessage('not borrowed from this pool');

        $source->releaseTarget($a);
    }

    #[Test]
    public function poolRejectsAnEmptySize(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PoolingTargetSource(Pooled::class, static fn (): Pooled => new Pooled(), 0);
    }

    #[Test]
    public function fiberLocalGivesEachFiberItsOwnTarget(): void
    {
        $source = new FiberLocalTargetSource(Pooled::class, static fn (): Pooled => new Pooled());
        $main = $source->getTarget();

        $inFibers = [];
        $fiberA = new \Fiber(function () use ($source, &$inFibers): void {
            $inFibers['a1'] = $source->getTarget();
            \Fiber::suspend();
            $inFibers['a2'] = $source->getTarget();
        });
        $fiberB = new \Fiber(function () use ($source, &$inFibers): void {
            $inFibers['b'] = $source->getTarget();
        });

        $fiberA->start();
        $fiberB->start();
        $fiberA->resume();

        self::assertSame($main, $source->getTarget(), 'code outside Fibers shares one target');
        self::assertSame($inFibers['a1'], $inFibers['a2'], 'a Fiber keeps its target across suspensions');
        self::assertNotSame($inFibers['a1'], $inFibers['b']);
        self::assertNotSame($inFibers['a1'], $main);
        self::assertSame(3, $source->getCreatedCount());
        self::assertSame(5, $source->getInvocationCount());
        self::assertFalse($source->isStatic());
        $source->releaseTarget($main);
        self::assertSame(Pooled::class, $source->getTargetClass());
    }

    #[Test]
    public function aFibersTargetIsDroppedWithTheFiber(): void
    {
        $source = new FiberLocalTargetSource(Pooled::class, static fn (): Pooled => new Pooled());
        $reference = null;
        $fiber = new \Fiber(function () use ($source, &$reference): void {
            $reference = \WeakReference::create($source->getTarget());
        });
        $fiber->start();
        self::assertNotNull($reference);
        self::assertNotNull($reference->get());

        unset($fiber);

        self::assertNull($reference->get());
    }
}
