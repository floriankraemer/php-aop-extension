<?php

declare(strict_types=1);

namespace AOP\Advice\Api;

/**
 * Interceptor that also implements introduced interfaces (Spring's IntroductionInterceptor).
 */
interface IntroductionInterceptorInterface extends MethodInterceptorInterface
{
    /**
     * Whether this interceptor implements (and so can introduce) the interface.
     */
    public function implementsInterface(string $interface): bool;
}
