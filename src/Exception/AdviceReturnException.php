<?php

declare(strict_types=1);

namespace AOP\Exception;

/**
 * An Around advice returned a value the advised method's return type does not accept.
 */
final class AdviceReturnException extends AopException
{
}
