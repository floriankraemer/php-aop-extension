<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Api\Pointcut;

use AOP\Exception\PointcutSyntaxException;
use AOP\Pointcut\Api\AttributeMatchingPointcut;
use AOP\Pointcut\Api\Pointcuts;
use AOP\Tests\Fixtures\Api\Account;
use AOP\Tests\Fixtures\Api\Mark;
use AOP\Tests\Fixtures\Api\MarkedAccount;
use AOP\Tests\Fixtures\Api\MarkedChild;
use AOP\Tests\Fixtures\Api\MethodMarked;
use AOP\Tests\Fixtures\Api\SubMark;
use AOP\Tests\Fixtures\Api\UsesMarkedTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AttributeMatchingPointcutTest extends TestCase
{
    /**
     * @param class-string $class
     */
    private function advised(AttributeMatchingPointcut $pointcut, string $class, string $method): bool
    {
        return Pointcuts::matches($pointcut, new \ReflectionMethod($class, $method), new \ReflectionClass($class));
    }

    #[Test]
    public function needsAtLeastOneAttribute(): void
    {
        $this->expectException(PointcutSyntaxException::class);

        new AttributeMatchingPointcut();
    }

    #[Test]
    public function classLevelMatchesEveryMethodOfAnAttributedClass(): void
    {
        $pointcut = new AttributeMatchingPointcut(Mark::class);

        self::assertTrue($this->advised($pointcut, MarkedAccount::class, 'plain'));
        self::assertTrue($this->advised($pointcut, MarkedAccount::class, 'both'));
        self::assertFalse($this->advised($pointcut, Account::class, 'getBalance'));
    }

    #[Test]
    public function methodLevelMatchesAttributedMethods(): void
    {
        $pointcut = new AttributeMatchingPointcut(null, Mark::class);

        self::assertTrue($this->advised($pointcut, MethodMarked::class, 'tagged'), 'a subclass of the attribute counts');
        self::assertFalse($this->advised($pointcut, MethodMarked::class, 'untagged'));
        self::assertTrue($this->advised($pointcut, MarkedAccount::class, 'both'));
    }

    #[Test]
    public function bothLevelsMustMatch(): void
    {
        $pointcut = new AttributeMatchingPointcut(Mark::class, Mark::class);

        self::assertTrue($this->advised($pointcut, MarkedAccount::class, 'both'));
        self::assertFalse($this->advised($pointcut, MarkedAccount::class, 'plain'));
        self::assertFalse($this->advised($pointcut, MethodMarked::class, 'tagged'), 'the class is not attributed');
    }

    #[Test]
    public function attributesAreNotInheritedUnlessAsked(): void
    {
        $direct = new AttributeMatchingPointcut(Mark::class);
        $inherited = new AttributeMatchingPointcut(Mark::class, null, true);

        self::assertFalse($this->advised($direct, MarkedChild::class, 'plain'));
        self::assertTrue($this->advised($inherited, MarkedChild::class, 'plain'), 'from the parent class');
        self::assertTrue($this->advised(new AttributeMatchingPointcut(SubMark::class, null, true), MarkedChild::class, 'contract'), 'from the interface');
        self::assertFalse($this->advised(new AttributeMatchingPointcut(SubMark::class, null, true), MarkedAccount::class, 'plain'));
    }

    #[Test]
    public function methodAttributesAreInheritedFromOverriddenMethodsAndInterfaces(): void
    {
        self::assertFalse($this->advised(new AttributeMatchingPointcut(null, Mark::class), MarkedChild::class, 'both'));
        self::assertTrue($this->advised(new AttributeMatchingPointcut(null, Mark::class, true), MarkedChild::class, 'both'), 'parent method');
        self::assertTrue($this->advised(new AttributeMatchingPointcut(null, Mark::class, true), MarkedChild::class, 'contract'), 'interface method');
        self::assertFalse($this->advised(new AttributeMatchingPointcut(null, Mark::class, true), MarkedChild::class, 'plain'));
    }

    #[Test]
    public function functionsCarryOnlyMethodLevelAttributes(): void
    {
        require_once __DIR__.'/../../../Fixtures/Api/functions.php';
        $marked = new \ReflectionFunction('AOP\Tests\Fixtures\Api\marked_function');
        $plain = new \ReflectionFunction('AOP\Tests\Fixtures\Api\get_value');

        self::assertTrue(Pointcuts::matches(new AttributeMatchingPointcut(null, Mark::class), $marked));
        self::assertFalse(Pointcuts::matches(new AttributeMatchingPointcut(null, Mark::class), $plain));
        self::assertFalse(Pointcuts::matches(new AttributeMatchingPointcut(Mark::class), $marked), 'a function has no class');
    }

    #[Test]
    public function classAttributesAreInheritedFromUsedTraits(): void
    {
        self::assertFalse($this->advised(new AttributeMatchingPointcut(Mark::class), UsesMarkedTrait::class, 'fromTrait'));
        self::assertTrue($this->advised(new AttributeMatchingPointcut(Mark::class, null, true), UsesMarkedTrait::class, 'fromTrait'));
    }
}
