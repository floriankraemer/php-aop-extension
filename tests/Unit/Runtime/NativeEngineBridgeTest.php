<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Runtime;

use AOP\Exception\AspectDefinitionException;
use AOP\Runtime\NativeEngineBridge;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NativeEngineBridgeTest extends TestCase
{
    protected function setUp(): void
    {
        if (NativeEngineBridge::available()) {
            self::markTestSkipped('The aop extension is loaded; these tests cover its absence.');
        }
    }

    #[Test]
    public function withoutTheExtensionNothingIsRegisteredOrActive(): void
    {
        $bridge = new NativeEngineBridge();

        self::assertFalse(NativeEngineBridge::available());
        self::assertFalse($bridge->isRegistered());
        self::assertFalse($bridge->interceptionActive());
        self::assertFalse($bridge->fieldInterceptionAvailable());
    }

    #[Test]
    public function everyEngineCallFailsWithAClearMessage(): void
    {
        $bridge = new NativeEngineBridge();
        $calls = [
            'register' => static fn () => $bridge->register(static fn (): int => 0, static fn (): null => null),
            'unregister' => static fn () => $bridge->unregister(),
            'reset' => static fn () => $bridge->reset(),
            'setIntroductions' => static fn () => $bridge->setIntroductions([]),
        ];

        foreach ($calls as $name => $call) {
            try {
                $call();
                self::fail($name.' must fail without the extension');
            } catch (AspectDefinitionException $e) {
                self::assertStringContainsString('ext-aop', $e->getMessage(), $name);
            }
        }
    }
}
