<?php

declare(strict_types=1);

namespace AOP\Aspect;

/**
 * Aspect instantiation model, named after AspectJ's `issingleton()`, `perthis`, `pertarget`, `percflow`,
 * `percflowbelow` and `pertypewithin`. The non-singleton models other than percflow(below) take an optional
 * restriction in `#[Aspect(pointcut: ...)]`; percflow(below) and pertypewithin require one.
 */
enum AspectScope: string
{
    case SINGLETON = 'singleton';
    case PER_TARGET = 'pertarget';
    case PER_THIS = 'perthis';
    case PER_CFLOW = 'percflow';
    case PER_CFLOW_BELOW = 'percflowbelow';
    case PER_TYPE_WITHIN = 'pertypewithin';
}
