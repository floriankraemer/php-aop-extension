<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Runtime;

use AOP\Runtime\AopContext;
use AOP\Tests\Fixtures\Jp;
use AOP\Tests\Fixtures\Service\Greeter;
use AOP\Tests\Fixtures\Targets\KindShapes;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AopContextTest extends TestCase
{
    #[Test]
    public function isNullOutsideAnAdvisedCall(): void
    {
        self::assertNull(AopContext::currentJoinPoint());
        self::assertNull(AopContext::currentObject());
    }

    #[Test]
    public function exposesTheInnermostJoinPointAndItsObject(): void
    {
        $outer = new Greeter();
        $inner = new KindShapes();
        $outerJp = Jp::invocation($outer, 'greet', ['x']);
        $innerJp = Jp::invocation($inner, 'inc', [1]);

        AopContext::push($outerJp);
        try {
            self::assertSame($outerJp, AopContext::currentJoinPoint());
            self::assertSame($outer, AopContext::currentObject());

            AopContext::push($innerJp);
            try {
                self::assertSame($innerJp, AopContext::currentJoinPoint());
                self::assertSame($inner, AopContext::currentObject());
            } finally {
                AopContext::pop();
            }

            self::assertSame($outerJp, AopContext::currentJoinPoint(), 'popping restores the outer join point');
            self::assertSame($outer, AopContext::currentObject());
        } finally {
            AopContext::pop();
        }

        self::assertNull(AopContext::currentJoinPoint());
    }

    #[Test]
    public function staticCodeHasNoCurrentObject(): void
    {
        $joinPoint = Jp::invocation(new KindShapes(), 'make');

        AopContext::push($joinPoint);
        try {
            self::assertSame($joinPoint, AopContext::currentJoinPoint());
            self::assertNull(AopContext::currentObject());
        } finally {
            AopContext::pop();
        }
    }

    #[Test]
    public function popOnAnEmptyStackIsHarmless(): void
    {
        AopContext::pop();

        self::assertNull(AopContext::currentJoinPoint());
    }
}
