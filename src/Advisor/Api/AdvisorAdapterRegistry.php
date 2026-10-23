<?php

declare(strict_types=1);

namespace AOP\Advisor\Api;

use AOP\Advice\AdviceType;
use AOP\Advice\Api\Interceptor\InterceptorBridge;
use AOP\Advice\Api\MethodInterceptorInterface as SpringMethodInterceptor;
use AOP\Interceptor\MethodInterceptorInterface;

/**
 * Turns advice objects into engine interceptors (Spring's AdvisorAdapterRegistry / GlobalAdvisorAdapterRegistry).
 *
 * Starts with adapters for: engine `AOP\Interceptor\MethodInterceptorInterface`, the API `MethodInterceptorInterface`,
 * `MethodBeforeAdviceInterface`, `AfterReturningAdviceInterface` and `ThrowsAdviceInterface`.
 * Registered adapters are asked first, in registration order, so a custom adapter may even override a default one.
 *
 * An adapter returns the ENGINE-level `AOP\Interceptor\MethodInterceptorInterface` (the one taking a `MethodInvocation`),
 * or a Spring-style `AOP\Advice\Api\MethodInterceptorInterface`, which is wrapped in `InterceptorBridge` here; see
 * AdvisorAdapterInterface.
 */
final class AdvisorAdapterRegistry
{
    /** @var list<AdvisorAdapterInterface> */
    private array $custom = [];

    /** @var list<AdvisorAdapterInterface> */
    private readonly array $defaults;

    public function __construct()
    {
        $this->defaults = Adapters::defaults();
    }

    /**
     * Adds an adapter. Registered adapters are asked before the default ones, in registration order.
     */
    public function registerAdvisorAdapter(AdvisorAdapterInterface $adapter): void
    {
        $this->custom[] = $adapter;
    }

    /**
     * Shorthand for registerAdvisorAdapter().
     */
    public function register(AdvisorAdapterInterface $adapter): void
    {
        $this->registerAdvisorAdapter($adapter);
    }

    public function supports(object $advice): bool
    {
        return null !== $this->adapterFor($advice);
    }

    /**
     * @throws UnknownAdviceTypeException
     * @throws \LogicException            when the adapter does not return an interceptor
     */
    public function getInterceptor(object $advice): MethodInterceptorInterface
    {
        $adapter = $this->require($advice);
        try {
            $interceptor = $adapter->getInterceptor($advice);
        } catch (\TypeError $e) {
            if (str_contains($e->getMessage(), '::getInterceptor(): Return value must be of type')) {
                throw self::wrongReturn($adapter, $e->getMessage(), $e);
            }
            throw $e;
        }

        // the Spring-style interface is accepted, too: the adapter did not have to know about the bridge
        return $interceptor instanceof MethodInterceptorInterface ? $interceptor : new InterceptorBridge($interceptor);
    }

    /**
     * The ordering slot of the advice; AROUND when its adapter does not say.
     *
     * @throws UnknownAdviceTypeException
     */
    public function adviceType(object $advice): AdviceType
    {
        $adapter = $this->require($advice);

        return $adapter instanceof TypedAdvisorAdapterInterface ? $adapter->adviceType($advice) : AdviceType::AROUND;
    }

    private static function wrongReturn(AdvisorAdapterInterface $adapter, string $reason, ?\Throwable $previous = null): \LogicException
    {
        return new \LogicException(sprintf(
            '%s::getInterceptor() must return the engine-level %s (invoke(MethodInvocation)) or a Spring-style %s (invoke(ProceedingJoinPointInterface)), which is wrapped for you. Declare the return type as %s|%s to return either. PHP said: %s',
            $adapter::class,
            MethodInterceptorInterface::class,
            SpringMethodInterceptor::class,
            MethodInterceptorInterface::class,
            SpringMethodInterceptor::class,
            $reason,
        ), 0, $previous);
    }

    private function require(object $advice): AdvisorAdapterInterface
    {
        return $this->adapterFor($advice) ?? throw UnknownAdviceTypeException::forAdvice($advice);
    }

    private function adapterFor(object $advice): ?AdvisorAdapterInterface
    {
        foreach ([...$this->custom, ...$this->defaults] as $adapter) {
            if ($adapter->supportsAdvice($advice)) {
                return $adapter;
            }
        }

        return null;
    }
}
