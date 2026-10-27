<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Runtime;

use AOP\Runtime\ReturnTypeCheck;
use AOP\Tests\Fixtures\Hierarchy\AbstractRepository;
use AOP\Tests\Fixtures\Hierarchy\UserRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReturnTypeCheckTest extends TestCase
{
    /**
     * @return iterable<string, array{string, mixed, bool}>
     */
    public static function plainCases(): iterable
    {
        yield 'int ok' => ['int', 1, true];
        yield 'int rejects string' => ['int', '1', false];
        yield 'float accepts int' => ['float', 1, true];
        yield 'float rejects string' => ['float', '1.0', false];
        yield 'string' => ['string', 's', true];
        yield 'bool' => ['bool', false, true];
        yield 'array' => ['array', [], true];
        yield 'iterable' => ['iterable', new \ArrayObject(), true];
        yield 'object' => ['object', new \stdClass(), true];
        yield 'callable' => ['callable', 'strlen', true];
        yield 'mixed' => ['mixed', null, true];
        yield 'void' => ['void', null, true];
        yield 'void rejects value' => ['void', 1, false];
        yield 'nullable' => ['?int', null, true];
        yield 'non nullable rejects null' => ['int', null, false];
        yield 'union' => ['int|string', 's', true];
        yield 'union rejects' => ['int|string', 1.5, false];
        yield 'intersection' => [\Countable::class.'&'.\Traversable::class, new \ArrayObject(), true];
        yield 'intersection rejects' => [\Countable::class.'&'.\Traversable::class, new \stdClass(), false];
        yield 'class' => [\ArrayObject::class, new \ArrayObject(), true];
        yield 'class rejects' => [\ArrayObject::class, new \stdClass(), false];
        yield 'true' => ['true', true, true];
        yield 'false rejects true' => ['false', true, false];
    }

    #[Test]
    #[DataProvider('plainCases')]
    public function checksPlainTypes(string $type, mixed $value, bool $expected): void
    {
        $function = eval('return static function (): '.$type.' { throw new \LogicException(); };');
        self::assertInstanceOf(\Closure::class, $function);
        $reflection = (new \ReflectionFunction($function))->getReturnType();

        self::assertSame($expected, ReturnTypeCheck::accepts($reflection, $value, null, \stdClass::class));
    }

    #[Test]
    public function aMissingTypeAcceptsAnything(): void
    {
        self::assertTrue(ReturnTypeCheck::accepts(null, 'x', null, \stdClass::class));
    }

    #[Test]
    public function staticMeansTheClassOfThisForInstanceMethods(): void
    {
        $type = (new \ReflectionMethod(UserRepository::class, 'selfReturn'))->getReturnType();
        $child = new class extends UserRepository {};

        self::assertTrue(ReturnTypeCheck::accepts($type, $child, $child, UserRepository::class));
        self::assertFalse(ReturnTypeCheck::accepts($type, new UserRepository(), $child, UserRepository::class), 'a parent instance is not the called class');
        self::assertTrue(ReturnTypeCheck::accepts($type, $child, new UserRepository(), UserRepository::class), 'a subclass instance is a static');
        self::assertFalse(ReturnTypeCheck::accepts($type, new \stdClass(), $child, UserRepository::class));
    }

    #[Test]
    public function staticMeansTheCalledClassForStaticMethods(): void
    {
        $type = (new \ReflectionMethod(UserRepository::class, 'selfReturn'))->getReturnType();

        self::assertTrue(ReturnTypeCheck::accepts($type, new UserRepository(), null, AbstractRepository::class, UserRepository::class));
        self::assertFalse(ReturnTypeCheck::accepts($type, new \stdClass(), null, AbstractRepository::class, UserRepository::class));
        self::assertFalse(ReturnTypeCheck::accepts($type, new UserRepository(), null, AbstractRepository::class), 'no called class known');
    }

    #[Test]
    public function selfMeansTheDeclaringClassForBothKinds(): void
    {
        $type = (new \ReflectionMethod(UserRepository::class, 'repo'))->getReturnType();

        self::assertTrue(ReturnTypeCheck::accepts($type, new UserRepository(), new UserRepository(), UserRepository::class));
        self::assertTrue(ReturnTypeCheck::accepts($type, new UserRepository(), null, UserRepository::class, UserRepository::class));
        self::assertFalse(ReturnTypeCheck::accepts($type, new \stdClass(), null, UserRepository::class, UserRepository::class));
        self::assertFalse(ReturnTypeCheck::accepts($type, new \ArrayObject(), new UserRepository(), UserRepository::class));
    }

    /**
     * @return iterable<string, array{string, mixed, bool}>
     */
    public static function edgeCases(): iterable
    {
        yield 'mixed accepts a value' => ['mixed', 1, true];
        yield 'never accepts nothing' => ['never', 1, false];
        yield 'null type' => ['null', null, true];
        yield 'null type rejects a value' => ['null', 0, false];
        yield 'false' => ['false', false, true];
        yield 'true rejects false' => ['true', false, false];
    }

    #[Test]
    #[DataProvider('edgeCases')]
    public function checksMixedNeverAndLiteralTypes(string $type, mixed $value, bool $expected): void
    {
        $function = eval('return static function (): '.$type.' { throw new \LogicException(); };');
        self::assertInstanceOf(\Closure::class, $function);

        self::assertSame($expected, ReturnTypeCheck::accepts((new \ReflectionFunction($function))->getReturnType(), $value, null, \stdClass::class));
    }
}
