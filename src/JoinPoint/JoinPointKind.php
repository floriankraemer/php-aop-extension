<?php

declare(strict_types=1);

namespace AOP\JoinPoint;

/**
 * The kinds of join points. The values of the execution, initialization, field and handler kinds are what the
 * C engine reports in the `kind` key of a join point descriptor; call kinds are derived by the PHP runtime.
 */
enum JoinPointKind: string
{
    case METHOD_EXECUTION = 'method-execution';
    case METHOD_CALL = 'method-call';
    case CONSTRUCTOR_EXECUTION = 'constructor-execution';
    case CONSTRUCTOR_CALL = 'constructor-call';
    case FUNCTION_EXECUTION = 'function-execution';
    case FUNCTION_CALL = 'function-call';
    case INITIALIZATION = 'initialization';
    case FIELD_GET = 'field-get';
    case FIELD_SET = 'field-set';
    case EXCEPTION_HANDLER = 'exception-handler';
    case ADVICE_EXECUTION = 'advice-execution';

    /**
     * The join point is a call site: the code seen from the caller.
     */
    public function isCall(): bool
    {
        return match ($this) {
            self::METHOD_CALL, self::CONSTRUCTOR_CALL, self::FUNCTION_CALL => true,
            default => false,
        };
    }

    /**
     * The join point is the execution of a method, constructor, function or advice.
     */
    public function isExecution(): bool
    {
        return match ($this) {
            self::METHOD_EXECUTION, self::CONSTRUCTOR_EXECUTION, self::FUNCTION_EXECUTION, self::ADVICE_EXECUTION => true,
            default => false,
        };
    }
}
