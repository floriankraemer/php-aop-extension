<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Pointcut\Pattern;

use AOP\Pointcut\Pattern\MethodPattern;
use AOP\Pointcut\Pattern\ModifierPattern;
use AOP\Pointcut\Pattern\NamePattern;
use AOP\Pointcut\Pattern\ParameterPattern;
use AOP\Pointcut\Pattern\TypePattern;
use AOP\Tests\Fixtures\Pointcut\Callee;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MethodPatternTest extends TestCase
{
    private static function constructorPattern(string $declaringType): MethodPattern
    {
        return new MethodPattern(new ModifierPattern(), null, new TypePattern($declaringType), new NamePattern('__construct'), new ParameterPattern([], true));
    }

    #[Test]
    public function aConstructorOfAnotherTypeNeverMatchesWithoutARuntimeClass(): void
    {
        $constructor = new \ReflectionMethod(Callee::class, '__construct');

        self::assertFalse(self::constructorPattern('Some\\Other\\Type')->matches($constructor));
        self::assertTrue(self::constructorPattern(Callee::class)->matches($constructor));
    }

    #[Test]
    public function aPatternWithADeclaringTypeNeverMatchesAFunction(): void
    {
        $function = new \ReflectionFunction('str_repeat');
        $pattern = new MethodPattern(new ModifierPattern(), null, new TypePattern('*'), new NamePattern('str_repeat'), new ParameterPattern([], true));

        self::assertFalse($pattern->matches($function));
    }
}
