<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Pointcut;

use AOP\Exception\PointcutSyntaxException;
use AOP\Pointcut\Expression\NamedPointcutResolverInterface;
use AOP\Pointcut\IfPointcut;
use AOP\Tests\Fixtures\Pointcut\Callee;
use AOP\Tests\Fixtures\Pointcut\Conditions;
use AOP\Tests\Fixtures\Pointcut\Shadow;
use AOP\Tests\Fixtures\Pointcut\StubJoinPoint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IfPointcutTest extends TestCase
{
    private function runtime(string $expression, string $method = 'find', string $aspect = ''): bool
    {
        $pointcut = Shadow::compile($expression, [], $aspect);
        $ctx = Shadow::method(Callee::class, $method);

        return $pointcut->matchesRuntime(Shadow::runtime(StubJoinPoint::method(new Callee(), $method), $ctx));
    }

    #[Test]
    public function theQualifiedFormCallsTheNamedStaticMethod(): void
    {
        self::assertTrue($this->runtime('if('.Conditions::class.'::always())'));
        self::assertFalse($this->runtime('if('.Conditions::class.'::never())'));
    }

    #[Test]
    public function theShortFormCallsTheDeclaringAspect(): void
    {
        self::assertTrue($this->runtime('if(always())', aspect: Conditions::class));
        self::assertFalse($this->runtime('if(never())', aspect: Conditions::class));
    }

    #[Test]
    public function theShortFormNeedsAnAspect(): void
    {
        $this->expectException(PointcutSyntaxException::class);
        $this->expectExceptionMessage('if(always()) needs the declaring aspect class');

        Shadow::compile('if(always())');
    }

    #[Test]
    public function theShortFormInsideANamedPointcutResolvesAgainstTheAspectDeclaringThatPointcut(): void
    {
        $resolver = new class implements NamedPointcutResolverInterface {
            public function resolve(?string $className, string $methodName, string $fromAspect): string
            {
                return 'if(always())';
            }
        };
        $pointcut = Shadow::compile(Conditions::class.'::cond()', [], 'Some\Aspect', $resolver);

        self::assertInstanceOf(IfPointcut::class, $pointcut);
        self::assertSame(Conditions::class, $pointcut->className);
        self::assertSame('always', $pointcut->methodName);
    }

    #[Test]
    public function aJoinPointParameterReceivesTheCurrentJoinPoint(): void
    {
        Conditions::$seen = [];

        self::assertTrue($this->runtime('if('.Conditions::class.'::forFind())', 'find'));
        self::assertFalse($this->runtime('if('.Conditions::class.'::forFind())', 'make'));
        self::assertSame(['find', 'make'], Conditions::$seen);
    }

    #[Test]
    public function nonPublicAndUntypedMethodsWork(): void
    {
        self::assertTrue($this->runtime('if('.Conditions::class.'::hidden())'));
        self::assertTrue($this->runtime('if('.Conditions::class.'::untypedTruthy())'));
    }

    #[Test]
    public function aNonBoolResultIsAnError(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('must return bool, got string');

        $this->runtime('if('.Conditions::class.'::untypedWrong())');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalid(): iterable
    {
        yield 'unknown class' => ['if(No\Such\Thing::cond())', 'class No\Such\Thing does not exist'];
        yield 'unknown method' => ['if('.Conditions::class.'::missing())', 'has no method missing()'];
        yield 'instance method' => ['if('.Conditions::class.'::instance())', 'must be static'];
        yield 'int return' => ['if('.Conditions::class.'::wrongReturn())', 'must return bool, it returns int'];
        yield 'nullable return' => ['if('.Conditions::class.'::nullableReturn())', 'must return bool, it returns ?bool'];
        yield 'two parameters' => ['if('.Conditions::class.'::twoParameters())', 'may only declare one parameter'];
        yield 'wrong parameter type' => ['if('.Conditions::class.'::wrongParameter())', 'may only declare one parameter'];
    }

    #[Test]
    #[DataProvider('invalid')]
    public function invalidConditionMethodsAreRejectedAtCompileTime(string $expression, string $message): void
    {
        try {
            Shadow::compile($expression);
            self::fail('Expected PointcutSyntaxException.');
        } catch (PointcutSyntaxException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
    }

    #[Test]
    public function ifIsRuntimeStaticallyTrueAndNotCallerSensitive(): void
    {
        $pointcut = Shadow::compile('if('.Conditions::class.'::never())');
        $ctx = Shadow::method(Callee::class, 'find');

        self::assertTrue($pointcut->isRuntime());
        self::assertFalse($pointcut->isCallerSensitive());
        self::assertSame([], $pointcut->bindings());
        self::assertTrue(Shadow::matches($pointcut, $ctx), 'only the runtime half decides');
    }

    #[Test]
    public function ifCombinesWithOtherDesignators(): void
    {
        $ctx = Shadow::method(Callee::class, 'find');
        $jp = StubJoinPoint::method(new Callee(), 'find');
        $always = 'if('.Conditions::class.'::always())';
        $never = 'if('.Conditions::class.'::never())';

        $and = Shadow::compile('execution(* *(..)) && '.$never);
        self::assertTrue(Shadow::matches($and, $ctx));
        self::assertFalse($and->matchesRuntime(Shadow::runtime($jp, $ctx)));
        self::assertTrue(Shadow::compile('execution(* *(..)) && '.$always)->matchesRuntime(Shadow::runtime($jp, $ctx)));
        self::assertTrue(Shadow::compile('!'.$never)->matchesRuntime(Shadow::runtime($jp, $ctx)));
        self::assertFalse(Shadow::compile('!'.$always)->matchesRuntime(Shadow::runtime($jp, $ctx)));
        self::assertTrue(Shadow::compile($never.' || '.$always)->matchesRuntime(Shadow::runtime($jp, $ctx)));
        self::assertFalse(Shadow::compile($never.' || (execution(* *(..)) && '.$never.')')->matchesRuntime(Shadow::runtime($jp, $ctx)));
    }

    #[Test]
    public function anOrBranchWhoseStaticPartFailsIsNotRescuedByTheCondition(): void
    {
        $pointcut = Shadow::compile('(execution(* *::nothing(..)) && if('.Conditions::class.'::always())) || execution(* *::find(..))');

        self::assertFalse($pointcut->matchesRuntime(Shadow::runtime(StubJoinPoint::method(new Callee(), 'find'), Shadow::method(Callee::class, 'make'))));
        self::assertTrue($pointcut->isRuntime());
    }

    #[Test]
    public function parseErrorsKeepTheirPosition(): void
    {
        foreach (['if()' => 3, 'if(Foo::)' => 8, 'if(Foo::bar)' => 11, 'if(Foo::bar(x))' => 12, 'if(bar' => 6] as $expression => $position) {
            try {
                Shadow::compile($expression);
                self::fail('Expected PointcutSyntaxException for '.$expression);
            } catch (PointcutSyntaxException $e) {
                self::assertStringContainsString('at position '.$position.' ', $e->getMessage(), $expression);
            }
        }
    }
}
