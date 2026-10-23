<?php

declare(strict_types=1);

namespace AOP\Config;

use AOP\Advisor\Advisor;
use AOP\Advisor\MetadataNamedPointcutResolver;
use AOP\Aspect\AspectMetadata;

/**
 * What a configuration array produced. The Weaver registers the pieces (see contract, "Spring API integration").
 */
final readonly class ConfigResult
{
    /**
     * @param list<AspectMetadata>                           $metadata
     * @param array<class-string, object|\Closure(): object> $aspectInstances      aspect class => instance, or a factory (singleton: called once; pertarget/perthis: once per scope object)
     * @param list<Advisor>                                  $aspectAdvisors       advisors of the configured aspects, in declaration order; bound through aspect instances
     * @param list<Advisor>                                  $programmaticAdvisors advisors of the `advisors` entries; they carry their interceptor
     */
    public function __construct(
        private array $metadata,
        private array $aspectInstances,
        private array $aspectAdvisors,
        private array $programmaticAdvisors,
        private MetadataNamedPointcutResolver $resolver,
    ) {
    }

    /**
     * @return list<AspectMetadata>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }

    /**
     * @return array<class-string, object|\Closure(): object>
     */
    public function aspectInstances(): array
    {
        return $this->aspectInstances;
    }

    /**
     * @return list<Advisor>
     */
    public function aspectAdvisors(): array
    {
        return $this->aspectAdvisors;
    }

    /**
     * @return list<Advisor>
     */
    public function programmaticAdvisors(): array
    {
        return $this->programmaticAdvisors;
    }

    /**
     * @return list<Advisor> aspect advisors, then programmatic advisors
     */
    public function advisors(): array
    {
        return [...$this->aspectAdvisors, ...$this->programmaticAdvisors];
    }

    /**
     * Resolves `Aspect::name()` references against the configured aspects (and attribute aspects).
     */
    public function namedPointcutResolver(): MetadataNamedPointcutResolver
    {
        return $this->resolver;
    }
}
