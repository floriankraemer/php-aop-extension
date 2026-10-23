<?php

declare(strict_types=1);

namespace AOP\Configurable;

use AOP\Advice\AdviceType;
use AOP\Advisor\Advisor;
use AOP\Attribute\Configurable;

/**
 * Spring's `@Configurable` support: configures objects created with `new` whose class carries `#[Configurable]`.
 *
 *     $aspect = new ConfigurableAspect(function (object $instance, Configurable $attribute) use ($container): void {
 *         $container->inject($instance, $attribute->service ?? $instance::class);
 *     });
 *     $weaver->addAdvisor($aspect->toAdvisor());
 *
 * The configurer runs once per object, after the outermost constructor completed, or before it runs when the
 * attribute says `preConstruction: true`. An exception in the constructor skips a post-construction configurer.
 * Subclasses of an annotated class are configured too (the nearest attribute up the hierarchy applies).
 *
 * The advisor needs the engine to dispatch `constructor-execution` join points, and the new object as `$this`.
 * Constructor-less classes (`initialization` join points) are configured when the engine passes the new object
 * as `$this` of that join point; otherwise they are skipped.
 */
final class ConfigurableAspect
{
    /** @var \Closure(object, Configurable): void */
    private readonly \Closure $configurer;

    /**
     * @param callable(object, Configurable): void $configurer
     * @param int                                  $order      lower runs first; the default makes it the outermost advice
     */
    public function __construct(callable $configurer, private readonly int $order = PHP_INT_MIN)
    {
        $this->configurer = $configurer(...);
    }

    /**
     * @param \ReflectionClass<object> $class
     */
    public static function attributeFor(\ReflectionClass $class): ?Configurable
    {
        for ($current = $class; false !== $current; $current = $current->getParentClass()) {
            $attributes = $current->getAttributes(Configurable::class);
            if ([] !== $attributes) {
                return $attributes[0]->newInstance();
            }
        }

        return null;
    }

    public function configure(object $instance, Configurable $attribute): void
    {
        ($this->configurer)($instance, $attribute);
    }

    public function toAdvisor(string $id = 'configurable'): Advisor
    {
        return new Advisor($id, null, $this->order, new ConfigurablePointcut(), new ConfigurableInterceptor($this), AdviceType::AROUND);
    }
}
