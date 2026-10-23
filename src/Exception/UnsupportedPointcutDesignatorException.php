<?php

declare(strict_types=1);

namespace AOP\Exception;

final class UnsupportedPointcutDesignatorException extends AopException
{
    public static function forDesignator(string $name): self
    {
        return new self(sprintf('Unsupported pointcut designator "%s".', $name));
    }
}
