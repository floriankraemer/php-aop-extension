<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Attribute;

use AOP\Attribute\After;
use AOP\Attribute\AfterReturning;
use AOP\Attribute\AfterThrowing;
use AOP\Attribute\Around;
use AOP\Attribute\Aspect;
use AOP\Attribute\Before;
use AOP\Attribute\Order;
use AOP\Attribute\Pointcut;
use AOP\Tests\Fixtures\Aspect\AttributeFixtureAspect;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AttributesTest extends TestCase
{
    private static int $generatedClasses = 0;

    #[Test]
    public function aspectAttributeIsPresentOnClass(): void
    {
        self::assertInstanceOf(Aspect::class, $this->classAttribute(Aspect::class));
    }

    #[Test]
    public function orderAttributeCarriesValue(): void
    {
        self::assertSame(10, $this->classAttribute(Order::class)->value);
    }

    #[Test]
    public function pointcutAttributeCarriesExpression(): void
    {
        self::assertSame(
            'execution(* App\Service\*Service::*(..))',
            $this->methodAttribute('servicePointcut', Pointcut::class)->expression,
        );
    }

    #[Test]
    public function adviceAttributesCarryPointcut(): void
    {
        self::assertSame('servicePointcut()', $this->methodAttribute('logBefore', Before::class)->pointcut);
        self::assertSame('servicePointcut()', $this->methodAttribute('logAfter', After::class)->pointcut);
        self::assertSame('servicePointcut()', $this->methodAttribute('wrapAround', Around::class)->pointcut);
        self::assertSame('servicePointcut()', $this->methodAttribute('logThrow', AfterThrowing::class)->pointcut);
        self::assertSame('servicePointcut()', $this->methodAttribute('logReturn', AfterReturning::class)->pointcut);
    }

    #[Test]
    public function afterReturningCarriesReturningName(): void
    {
        self::assertSame('result', $this->methodAttribute('logReturn', AfterReturning::class)->returning);
    }

    #[Test]
    public function afterReturningReturningNameDefaultsToNull(): void
    {
        self::assertNull($this->methodAttribute('logReturnUnbound', AfterReturning::class)->returning);
    }

    #[Test]
    public function afterThrowingCarriesThrowingName(): void
    {
        self::assertSame('error', $this->methodAttribute('logThrow', AfterThrowing::class)->throwing);
    }

    /**
     * @return iterable<string, array{class-string, int}>
     */
    public static function declaredTargetProvider(): iterable
    {
        yield 'aspect' => [Aspect::class, \Attribute::TARGET_CLASS];
        yield 'order' => [Order::class, \Attribute::TARGET_CLASS];
        yield 'pointcut' => [Pointcut::class, \Attribute::TARGET_METHOD];
        yield 'before' => [Before::class, \Attribute::TARGET_METHOD];
        yield 'after' => [After::class, \Attribute::TARGET_METHOD];
        yield 'around' => [Around::class, \Attribute::TARGET_METHOD];
        yield 'after returning' => [AfterReturning::class, \Attribute::TARGET_METHOD];
        yield 'after throwing' => [AfterThrowing::class, \Attribute::TARGET_METHOD];
    }

    /**
     * @param class-string $attributeClass
     */
    #[Test]
    #[DataProvider('declaredTargetProvider')]
    public function attributeDeclaresExpectedTarget(string $attributeClass, int $target): void
    {
        $declaration = (new \ReflectionClass($attributeClass))->getAttributes(\Attribute::class)[0]->newInstance();

        self::assertSame($target, $declaration->flags);
    }

    #[Test]
    public function adviceAttributeOnClassIsRejectedWhenInstantiated(): void
    {
        // Compiled at runtime: PHPStan rightly refuses a static fixture with this placement.
        $class = $this->declareClass(
            "#[\\AOP\\Attribute\\Before('servicePointcut()')]\nfinal class %s {}",
        );

        $attribute = (new \ReflectionClass($class))->getAttributes(Before::class)[0];

        $this->expectException(\Error::class);
        $attribute->newInstance();
    }

    #[Test]
    public function aspectAttributeOnMethodIsRejectedWhenInstantiated(): void
    {
        $class = $this->declareClass(
            "final class %s\n{\n    #[\\AOP\\Attribute\\Aspect]\n    public function misplaced(): void {}\n}",
        );

        $attribute = (new \ReflectionMethod($class, 'misplaced'))->getAttributes(Aspect::class)[0];

        $this->expectException(\Error::class);
        $attribute->newInstance();
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $attributeClass
     *
     * @return T
     */
    private function classAttribute(string $attributeClass): object
    {
        $attributes = (new \ReflectionClass(AttributeFixtureAspect::class))->getAttributes($attributeClass);
        self::assertCount(1, $attributes, sprintf('Expected one %s attribute on class.', $attributeClass));

        return $attributes[0]->newInstance();
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $attributeClass
     *
     * @return T
     */
    private function methodAttribute(string $method, string $attributeClass): object
    {
        $attributes = (new \ReflectionMethod(AttributeFixtureAspect::class, $method))->getAttributes($attributeClass);
        self::assertCount(1, $attributes, sprintf('Expected one %s attribute on %s().', $attributeClass, $method));

        return $attributes[0]->newInstance();
    }

    /**
     * Declares a class in the Generated namespace from a source template, where %s is replaced by a unique name.
     *
     * @return class-string
     */
    private function declareClass(string $template): string
    {
        $name = 'MisplacedAttributeFixture'.++self::$generatedClasses;
        $fqcn = 'AOP\Tests\Generated\\'.$name;

        eval('namespace AOP\Tests\Generated;'.sprintf($template, $name));

        if (!class_exists($fqcn, false)) {
            throw new \LogicException(sprintf('Failed to declare %s.', $fqcn));
        }

        return $fqcn;
    }
}
