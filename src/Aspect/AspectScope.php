<?php

declare(strict_types=1);

namespace AOP\Aspect;

/**
 * Aspect instantiation model, named after AspectJ's `issingleton()`, `pertarget` and `perthis`.
 */
enum AspectScope: string
{
    case SINGLETON = 'singleton';
    case PER_TARGET = 'pertarget';
    case PER_THIS = 'perthis';
}
