<?php

declare(strict_types=1);

namespace AOP\Attribute;

/**
 * AspectJ's `declare error`: when a join point matches `$pointcut`, an `AOP\Exception\DeclareErrorException`
 * naming the join point and carrying `$message` is thrown instead of running it.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final class DeclareError
{
    public function __construct(
        public readonly string $pointcut,
        public readonly string $message,
    ) {
    }
}
