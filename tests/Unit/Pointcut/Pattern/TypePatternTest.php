<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Pointcut\Pattern;

use AOP\Pointcut\Pattern\TypePattern;
use AOP\Tests\Fixtures\Hierarchy\AbstractRepository;
use AOP\Tests\Fixtures\Hierarchy\Plain;
use AOP\Tests\Fixtures\Hierarchy\ReadableInterface;
use AOP\Tests\Fixtures\Hierarchy\RepositoryInterface;
use AOP\Tests\Fixtures\Hierarchy\Sub\Deep\Thing;
use AOP\Tests\Fixtures\Hierarchy\Sub\ExtraRepository;
use AOP\Tests\Fixtures\Hierarchy\UserRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TypePatternTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function matchCases(): iterable
    {
        yield 'exact' => ['App\Service\UserService', 'App\Service\UserService', true];
        yield 'exact other' => ['App\Service\UserService', 'App\Service\Other', false];
        yield 'leading backslash on subject' => ['App\Service\UserService', '\App\Service\UserService', true];
        yield 'no partial match suffix' => ['App\Service', 'App\ServiceX', false];
        yield 'no partial match prefix' => ['Service', 'App\Service', false];
        yield 'no partial match child' => ['App\Service', 'App\Service\Child', false];
        yield 'star in segment' => ['App\Service\*Service', 'App\Service\UserService', true];
        yield 'star in segment no namespace' => ['App\Service\*Service', 'App\Service\Sub\UserService', false];
        yield 'star prefix' => ['*Repository', 'UserRepository', true];
        yield 'star prefix namespaced' => ['*Repository', 'App\UserRepository', false];
        yield 'star suffix' => ['App\User*', 'App\UserService', true];
        yield 'star whole segment' => ['App\*\Foo', 'App\Bar\Foo', true];
        yield 'star whole segment too deep' => ['App\*\Foo', 'App\Bar\Baz\Foo', false];
        yield 'trailing star needs a class' => ['App\Service\*', 'App\Service', false];
        yield 'double star trailing direct' => ['App\Service\**', 'App\Service\X', true];
        yield 'double star trailing nested' => ['App\Service\**', 'App\Service\Sub\Y', true];
        yield 'double star trailing other ns' => ['App\Service\**', 'App\Other\Y', false];
        yield 'double star trailing prefix ns' => ['App\Service\**', 'App\ServiceX\Y', false];
        yield 'double star middle zero' => ['App\**\Foo', 'App\Foo', true];
        yield 'double star middle many' => ['App\**\Foo', 'App\A\B\Foo', true];
        yield 'double star middle wrong tail' => ['App\**\Foo', 'App\A\B\Bar', false];
        yield 'double star leading' => ['**\Foo', 'Foo', true];
        yield 'double star leading nested' => ['**\Foo', 'A\B\Foo', true];
        yield 'double star alone' => ['**', 'Any\Thing', true];
        yield 'star alone matches builtin name' => ['*', 'int', true];
        yield 'builtin literal' => ['int', 'int', true];
        yield 'builtin literal mismatch' => ['int', 'string', false];
        yield 'regex chars are literal' => ['App\Foo.Bar', 'App\FooXBar', false];
    }

    #[Test]
    #[DataProvider('matchCases')]
    public function matchesFqcn(string $pattern, string $fqcn, bool $expected): void
    {
        self::assertSame($expected, (new TypePattern($pattern))->matches($fqcn));
    }

    #[Test]
    public function leadingBackslashInPatternIsIgnored(): void
    {
        self::assertTrue((new TypePattern('\App\Foo'))->matches('App\Foo'));
    }

    /**
     * @return iterable<string, array{string, bool, class-string, bool}>
     */
    public static function hierarchyCases(): iterable
    {
        $fx = 'AOP\Tests\Fixtures\Hierarchy\\';
        yield 'exact class' => [$fx.'UserRepository', false, UserRepository::class, true];
        yield 'exact does not match subclass' => [$fx.'UserRepository', false, ExtraRepository::class, false];
        yield 'plus on class matches itself' => [$fx.'UserRepository', true, UserRepository::class, true];
        yield 'plus on class matches subclass' => [$fx.'UserRepository', true, ExtraRepository::class, true];
        yield 'plus on class not parent' => [$fx.'UserRepository', true, AbstractRepository::class, false];
        yield 'plus on abstract matches grandchild' => [$fx.'AbstractRepository', true, ExtraRepository::class, true];
        yield 'plus on interface matches implementor' => [$fx.'RepositoryInterface', true, UserRepository::class, true];
        yield 'plus on parent interface matches implementor' => [$fx.'ReadableInterface', true, ExtraRepository::class, true];
        yield 'plus on interface matches sub interface' => [$fx.'ReadableInterface', true, RepositoryInterface::class, true];
        yield 'plus on interface not unrelated' => [$fx.'RepositoryInterface', true, Plain::class, false];
        yield 'interface without plus does not match implementor' => [$fx.'RepositoryInterface', false, UserRepository::class, false];
        yield 'glob with plus' => [$fx.'*Repository', true, ExtraRepository::class, true];
        yield 'glob ns with plus' => [$fx.'Sub\**', true, Thing::class, true];
        yield 'glob no plus no match' => [$fx.'Sub\*', false, Thing::class, false];
    }

    /**
     * @param class-string $subject
     */
    #[Test]
    #[DataProvider('hierarchyCases')]
    public function matchesTypeHonoursSubtypes(string $pattern, bool $plus, string $subject, bool $expected): void
    {
        $result = (new TypePattern($pattern, false, $plus))->matchesType(new \ReflectionClass($subject));

        self::assertSame($expected, $result);
    }

    #[Test]
    public function matchesNameResolvesSubtypesForExistingClasses(): void
    {
        $pattern = new TypePattern(Plain::class, false, true);

        self::assertTrue($pattern->matchesName(Plain::class));
        self::assertFalse($pattern->matchesName('Does\Not\Exist'));
        self::assertTrue((new TypePattern(ReadableInterface::class, false, true))->matchesName(UserRepository::class));
    }

    #[Test]
    public function exposesFlags(): void
    {
        $pattern = new TypePattern('Foo', true, true);

        self::assertTrue($pattern->nullable);
        self::assertTrue($pattern->includeSubtypes);
    }
}
