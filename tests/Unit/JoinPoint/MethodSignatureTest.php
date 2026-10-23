<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\JoinPoint;

use AOP\JoinPoint\MethodSignature;
use AOP\Tests\Fixtures\Service\UserService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MethodSignatureTest extends TestCase
{
    #[Test]
    public function fromReflectionExposesClassAndMethodName(): void
    {
        $signature = MethodSignature::fromReflection(new \ReflectionMethod(UserService::class, 'findUser'));

        self::assertSame(UserService::class, $signature->className);
        self::assertSame('findUser', $signature->methodName);
        self::assertSame('findUser', $signature->method->getName());
    }

    #[Test]
    public function shortStringUsesShortClassName(): void
    {
        $signature = MethodSignature::fromReflection(new \ReflectionMethod(UserService::class, 'findUser'));

        self::assertSame('UserService::findUser(..)', $signature->toShortString());
    }

    #[Test]
    public function longStringUsesFqcnAndParameterTypes(): void
    {
        $signature = MethodSignature::fromReflection(new \ReflectionMethod(UserService::class, 'findUser'));

        self::assertSame(
            'AOP\Tests\Fixtures\Service\UserService::findUser(int, ...string)',
            $signature->toLongString(),
        );
    }
}
