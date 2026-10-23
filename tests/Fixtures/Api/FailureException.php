<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Api;

final class FailureException extends \RuntimeException implements Failure
{
}
