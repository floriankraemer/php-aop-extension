<?php

declare(strict_types=1);

namespace AOP\Attribute;

/**
 * Introduction (Spring's `@DeclareParents`): classes matching `$typePattern` also implement `$interface`,
 * delegating its methods to one `$defaultImpl` instance per object, created on first use without constructor arguments.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final class DeclareParents
{
    /**
     * @param string $typePattern type pattern as in `within()`; a trailing `+` includes subtypes
     */
    public function __construct(
        public readonly string $typePattern,
        public readonly string $interface,
        public readonly string $defaultImpl,
    ) {
    }
}
