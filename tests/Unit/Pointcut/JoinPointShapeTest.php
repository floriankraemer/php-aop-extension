<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Pointcut;

use AOP\JoinPoint\JoinPointKind;
use AOP\Pointcut\EnclosingContext;
use AOP\Pointcut\JoinPointShape;
use AOP\Pointcut\StaticMatchContext;
use AOP\Tests\Fixtures\Pointcut\Callee;
use AOP\Tests\Fixtures\Pointcut\Caller;
use AOP\Tests\Fixtures\Pointcut\Shadow;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class JoinPointShapeTest extends TestCase
{
    /**
     * @param list<\ReflectionClass<object>> $classes
     *
     * @return list<string>
     */
    private static function names(array $classes): array
    {
        return array_map(static fn (\ReflectionClass $c): string => $c->getName(), $classes);
    }

    #[Test]
    public function aCallInsideCodeWithoutAFunctionIsEnclosedByTheCallersClass(): void
    {
        $ctx = new StaticMatchContext(JoinPointKind::METHOD_CALL, new \ReflectionClass(Callee::class), null, null, null, new EnclosingContext(new \ReflectionClass(Caller::class)));

        self::assertSame([Caller::class], self::names(JoinPointShape::enclosingClasses($ctx, null)));
        self::assertNull(JoinPointShape::enclosingFunction($ctx, new \ReflectionMethod(Callee::class, 'find')), 'a call is enclosed by the caller, which has no function here');
    }

    #[Test]
    public function aCallWithoutAnyKnownCallerHasNoEnclosingClass(): void
    {
        $ctx = Shadow::method(Callee::class, 'find', JoinPointKind::METHOD_CALL);

        self::assertSame([], JoinPointShape::enclosingClasses($ctx, null));
        self::assertSame([], JoinPointShape::enclosingClasses(new StaticMatchContext(JoinPointKind::METHOD_CALL, null, null, null, null, new EnclosingContext()), null), 'a caller that is neither a class nor a method');
    }

    #[Test]
    public function executedCodeIsEnclosedByItsDeclaringClassOrNothingForFunctions(): void
    {
        $method = new \ReflectionMethod(Callee::class, 'find');

        self::assertSame([Callee::class], self::names(JoinPointShape::enclosingClasses(Shadow::method(Callee::class, 'find'), $method)));
        self::assertSame([], JoinPointShape::enclosingClasses(Shadow::function('strlen'), new \ReflectionFunction('strlen')));
        self::assertSame($method, JoinPointShape::enclosingFunction(Shadow::method(Callee::class, 'find'), $method));
    }
}
