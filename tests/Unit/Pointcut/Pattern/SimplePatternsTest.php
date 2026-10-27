<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Pointcut\Pattern;

use AOP\Pointcut\Pattern\ModifierPattern;
use AOP\Pointcut\Pattern\NamePattern;
use AOP\Tests\Fixtures\Hierarchy\AbstractRepository;
use AOP\Tests\Fixtures\Hierarchy\UserRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SimplePatternsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function nameCases(): iterable
    {
        yield 'exact' => ['find', 'find', true];
        yield 'exact miss' => ['find', 'findAll', false];
        yield 'star all' => ['*', 'anything', true];
        yield 'prefix' => ['find*', 'findAll', true];
        yield 'prefix miss' => ['find*', 'refind', false];
        yield 'suffix' => ['*Action', 'indexAction', true];
        yield 'middle' => ['get*Name', 'getFirstName', true];
        yield 'dots are literal' => ['a.b', 'axb', false];
        yield 'service id with dots and dashes' => ['app.*-service', 'app.user-service', true];
    }

    #[Test]
    #[DataProvider('nameCases')]
    public function namePatternGlobs(string $pattern, string $name, bool $expected): void
    {
        self::assertSame($expected, (new NamePattern($pattern))->matches($name));
    }

    #[Test]
    public function modifierPatternFiltersVisibility(): void
    {
        $public = new \ReflectionMethod(UserRepository::class, 'find');
        $protected = new \ReflectionMethod(UserRepository::class, 'internal');
        $protectedParent = new \ReflectionMethod(AbstractRepository::class, 'hydrate');

        self::assertTrue((new ModifierPattern(null))->matches($public));
        self::assertTrue((new ModifierPattern(null))->matches($protected));
        self::assertTrue((new ModifierPattern('public'))->matches($public));
        self::assertFalse((new ModifierPattern('public'))->matches($protected));
        self::assertTrue((new ModifierPattern('protected'))->matches($protectedParent));
        self::assertFalse((new ModifierPattern('protected'))->matches($public));
    }

    #[Test]
    public function modifierPatternRejectsUnknownModifiers(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(ModifierPattern::unknownMessage('readonly'));

        new ModifierPattern(['public', '!readonly']);
    }
}
