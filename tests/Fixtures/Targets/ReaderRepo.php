<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Targets;

use AOP\Tests\Fixtures\Log;

final class ReaderRepo implements ReaderInterface, RepoInterface
{
    public function find(): object
    {
        Log::add('find');

        return new \stdClass();
    }
}
