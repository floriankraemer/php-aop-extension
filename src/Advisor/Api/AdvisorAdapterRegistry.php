<?php

declare(strict_types=1);

namespace AOP\Advisor\Api;

use AOP\Advice\AdviceType;
use AOP\Interceptor\MethodInterceptorInterface;

/**
 * Turns advice objects into engine interceptors (Spring's AdvisorAdapterRegistry / GlobalAdvisorAdapterRegistry).
 *
 * Starts with adapters for: engine `AOP\Interceptor\MethodInterceptorInterface`, the API `MethodInterceptorInterface`,
 * `MethodBeforeAdviceInterface`, `AfterReturningAdviceInterface` and `ThrowsAdviceInterface`.
 * Registered adapters are asked first, in registration order, so a custom adapter may even override a default one.
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

    public function registerAdvisorAdapter(AdvisorAdapterInterface $adapter): void
    {
        $this->custom[] = $adapter;
    }

    public function supports(object $advice): bool
    {
        return null !== $this->adapterFor($advice);
    }

    /**
     * @throws UnknownAdviceTypeException
     */
    public function getInterceptor(object $advice): MethodInterceptorInterface
    {
        return $this->require($advice)->getInterceptor($advice);
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
