<?php

declare(strict_types=1);

namespace AOP\Pointcut;

enum BindingKind: string
{
    case ARGS = 'args';
    case THIS = 'this';
    case TARGET = 'target';
    case ANNOTATION = 'annotation';
    case WITHIN = 'within';
    case AT_TARGET = 'at_target';
    case AT_ARGS = 'at_args';
    case AT_THIS = 'at_this';
    case AT_WITHINCODE = 'at_withincode';
}
