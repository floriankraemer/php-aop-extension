<?php

declare(strict_types=1);

namespace AOP\Config;

use AOP\Exception\AopException;

/**
 * A configuration array that cannot be turned into aspects; the message names the offending entry (`aop.aspects[0].advice[2]`).
 */
final class ConfigurationException extends AopException
{
}
