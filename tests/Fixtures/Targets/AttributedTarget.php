<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Targets;

class AttributedTarget
{
    #[Audited]
    public function login(string $user, #[\SensitiveParameter] string $password, #[Audited] string $note = ''): string
    {
        return $user.':'.strlen($password).$note;
    }
}
