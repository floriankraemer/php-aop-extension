<?php

declare(strict_types=1);

namespace AOP\Advisor\Api;

use AOP\Exception\AopException;

final class UnknownAdviceTypeException extends AopException
{
    public static function forAdvice(object $advice): self
    {
        return new self(sprintf('No advisor adapter supports advice of type %s. Implement one of the advice interfaces, or register an AdvisorAdapterInterface for it.', $advice::class));
    }
}
