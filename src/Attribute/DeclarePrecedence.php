<?php

declare(strict_types=1);

namespace AOP\Attribute;

/**
 * AspectJ's `declare precedence`: a comma separated list of aspect type patterns, highest precedence (outermost)
 * first. `*` stands for every aspect the list does not name, e.g. `'AuditAspect, TxAspect, *'`. A trailing `+`
 * includes subtypes. It overrides `#[Order]` between the aspects it relates.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final class DeclarePrecedence
{
    public function __construct(public readonly string $aspects)
    {
    }
}
