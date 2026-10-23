<?php

declare(strict_types=1);

namespace AOP\Tests\Unit\Runtime;

use AOP\Proxy\ProxyFactory;
use AOP\Runtime\AopContext;
use AOP\Tests\Fixtures\Aspect\TargetsAspect;
use AOP\Tests\Fixtures\Targets\ContextProbe;
use AOP\Tests\Fixtures\Targets\ContextProbeInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AopContextTest extends TestCase
{
    protected function setUp(): void
    {
        ContextProbe::$seen = [];
    }

    private function proxy(ContextProbe $target): ContextProbeInterface
    {
        $proxy = ProxyFactory::fromAspects([new TargetsAspect()])->createProxy($target);
        self::assertInstanceOf(ContextProbeInterface::class, $proxy);

        return $proxy;
    }

    #[Test]
    public function isNullOutsideAnAdvisedCall(): void
    {
        self::assertNull(AopContext::currentProxy());
    }

    #[Test]
    public function isTheProxyInsideAnAdvisedCallAndNullAfterwards(): void
    {
        $proxy = $this->proxy(new ContextProbe());

        $proxy->probe();

        self::assertSame([$proxy, $proxy], ContextProbe::$seen);
        self::assertNull(AopContext::currentProxy());
    }

    #[Test]
    public function nestedInvocationThroughAnotherProxyRestoresTheOuterProxy(): void
    {
        $inner = $this->proxy(new ContextProbe());
        $outer = $this->proxy(new ContextProbe($inner));

        $outer->probe();

        self::assertSame([$outer, $inner, $inner, $outer], ContextProbe::$seen);
        self::assertNull(AopContext::currentProxy());
    }

    #[Test]
    public function isRestoredAfterAnException(): void
    {
        $inner = $this->proxy(new ContextProbe(null, true));
        $outer = $this->proxy(new ContextProbe(new class($inner) implements ContextProbeInterface {
            public function __construct(private readonly ContextProbeInterface $inner)
            {
            }

            public function probe(): void
            {
                try {
                    $this->inner->probe();
                } catch (\LogicException) {
                    ContextProbe::$seen[] = AopContext::currentProxy();
                }
            }
        }));

        $outer->probe();

        self::assertSame([$outer, $inner, $inner, $outer, $outer], ContextProbe::$seen);
        self::assertNull(AopContext::currentProxy());
    }
}
