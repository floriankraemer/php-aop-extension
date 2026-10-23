<?php

declare(strict_types=1);

namespace AOP\Attribute;

/**
 * Marks a class whose instances get their dependencies configured by the container even though the application
 * creates them with `new` (Spring's `@Configurable`). Needs a `ConfigurableAspect` registered with the Weaver.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Configurable
{
    /**
     * @param bool        $autowire        tells the configurer to autowire the instance (what that means is up to the configurer)
     * @param bool        $preConstruction configure before the constructor runs instead of after it, so the constructor can use the injected services
     * @param string|null $service         the name of the service definition to configure the instance from; default: the class name
     */
    public function __construct(
        public bool $autowire = false,
        public bool $preConstruction = false,
        public ?string $service = null,
    ) {
    }
}
