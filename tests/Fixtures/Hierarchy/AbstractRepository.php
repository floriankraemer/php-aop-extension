<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Hierarchy;

abstract class AbstractRepository implements RepositoryInterface
{
    public function save(object $entity): void
    {
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    protected function hydrate(array $row): array
    {
        return $row;
    }
}
