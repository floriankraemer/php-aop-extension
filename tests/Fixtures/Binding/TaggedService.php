<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Binding;

#[Tagged('class')]
class TaggedService extends BaseService
{
    #[Tagged('method')]
    public function own(): string
    {
        return 'own';
    }

    public function accept(object $subject): string
    {
        return $subject::class;
    }
}
