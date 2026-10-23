<?php

declare(strict_types=1);

namespace AOP\Attribute;

/**
 * AspectJ's `declare warning`: when a join point first matches `$pointcut`, an `E_USER_WARNING` naming the
 * join point and carrying `$message` is raised.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final class DeclareWarning
{
    public function __construct(
        public readonly string $pointcut,
        public readonly string $message,
    ) {
    }
}
