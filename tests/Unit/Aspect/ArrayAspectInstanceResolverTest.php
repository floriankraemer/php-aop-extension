<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Aspect;

use AOP\Aspect\ArrayAspectInstanceResolver;
use AOP\Exception\AspectDefinitionException;
use AOP\Tests\Fixtures\Aspect\AttributeFixtureAspect;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ArrayAspectInstanceResolverTest extends TestCase
{
    #[Test]
    public function returnsRegisteredInstance(): void
    {
        $aspect = new AttributeFixtureAspect();
        $resolver = new ArrayAspectInstanceResolver([AttributeFixtureAspect::class => $aspect]);

        self::assertSame($aspect, $resolver->get(AttributeFixtureAspect::class));
    }

    #[Test]
    public function closureIsNotInvokedBeforeFirstGet(): void
    {
        $calls = 0;
        $resolver = new ArrayAspectInstanceResolver([
            AttributeFixtureAspect::class => function () use (&$calls): AttributeFixtureAspect {
                ++$calls;

                return new AttributeFixtureAspect();
            },
        ]);

        self::assertSame(0, $calls);
        $resolver->get(AttributeFixtureAspect::class);
        self::assertSame(1, $calls);
    }

    #[Test]
    public function closureIsInvokedOnceAndInstanceIsCached(): void
    {
        $calls = 0;
        $resolver = new ArrayAspectInstanceResolver([
            AttributeFixtureAspect::class => function () use (&$calls): AttributeFixtureAspect {
                ++$calls;

                return new AttributeFixtureAspect();
            },
        ]);

        $first = $resolver->get(AttributeFixtureAspect::class);
        $second = $resolver->get(AttributeFixtureAspect::class);

        self::assertSame(1, $calls);
        self::assertSame($first, $second);
    }

    #[Test]
    public function unknownAspectThrows(): void
    {
        $resolver = new ArrayAspectInstanceResolver([]);

        $this->expectException(AspectDefinitionException::class);
        $resolver->get(AttributeFixtureAspect::class);
    }

    #[Test]
    public function singletonIgnoresScopeAbsence(): void
    {
        $resolver = new ArrayAspectInstanceResolver([AttributeFixtureAspect::class => static fn (): AttributeFixtureAspect => new AttributeFixtureAspect()]);

        self::assertSame($resolver->get(AttributeFixtureAspect::class), $resolver->get(AttributeFixtureAspect::class));
    }

    #[Test]
    public function scopeObjectsGetDistinctInstancesCreatedOncePerObject(): void
    {
        $calls = 0;
        $resolver = new ArrayAspectInstanceResolver([
            AttributeFixtureAspect::class => function () use (&$calls): AttributeFixtureAspect {
                ++$calls;

                return new AttributeFixtureAspect();
            },
        ]);
        $a = new \stdClass();
        $b = new \stdClass();

        $forA = $resolver->get(AttributeFixtureAspect::class, $a);
        $forB = $resolver->get(AttributeFixtureAspect::class, $b);

        self::assertNotSame($forA, $forB);
        self::assertSame($forA, $resolver->get(AttributeFixtureAspect::class, $a));
        self::assertSame($forB, $resolver->get(AttributeFixtureAspect::class, $b));
        self::assertSame(2, $calls);
        self::assertNotSame($forA, $resolver->get(AttributeFixtureAspect::class), 'the singleton slot is separate');
    }

    #[Test]
    public function scopedInstanceIsReleasedWithItsScopeObject(): void
    {
        $resolver = new ArrayAspectInstanceResolver([AttributeFixtureAspect::class => static fn (): AttributeFixtureAspect => new AttributeFixtureAspect()]);
        $scope = new \stdClass();
        $instance = \WeakReference::create($resolver->get(AttributeFixtureAspect::class, $scope));

        self::assertNotNull($instance->get());
        unset($scope);
        gc_collect_cycles();

        self::assertNull($instance->get());
    }

    #[Test]
    public function unknownAspectWithScopeThrows(): void
    {
        $this->expectException(AspectDefinitionException::class);
        (new ArrayAspectInstanceResolver([]))->get(AttributeFixtureAspect::class, new \stdClass());
    }

    #[Test]
    public function aFactoryReturningTheWrongTypeThrows(): void
    {
        $resolver = new ArrayAspectInstanceResolver([AttributeFixtureAspect::class => static fn (): object => new \stdClass()]);

        $this->expectException(AspectDefinitionException::class);
        $this->expectExceptionMessage('is of type "stdClass"');

        $resolver->get(AttributeFixtureAspect::class);
    }
}
