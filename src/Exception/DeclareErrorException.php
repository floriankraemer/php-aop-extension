<?php

declare(strict_types=1);

namespace AOP\Exception;

/**
 * Raised by `#[DeclareError]` when a join point matches its pointcut.
 */
final class DeclareErrorException extends AopException
{
}
