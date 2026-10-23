<?php

declare(strict_types=1);

namespace AOP\Exception;

final class PointcutSyntaxException extends AopException
{
    public static function at(string $message, int $position, string $expression): self
    {
        return new self(sprintf('%s at position %d in pointcut "%s".', $message, $position, $expression));
    }
}
