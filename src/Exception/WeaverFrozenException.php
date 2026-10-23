<?php

declare(strict_types=1);

namespace AOP\Exception;

/**
 * Thrown when the advice configuration of a frozen Weaver (or frozen advised instance) is changed.
 */
final class WeaverFrozenException extends AopException
{
}
