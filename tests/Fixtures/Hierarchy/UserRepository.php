<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Hierarchy;

#[Marker('user')]
class UserRepository extends AbstractRepository
{
    public function find(int $id): ?object
    {
        return null;
    }

    /**
     * @param \Countable&\Traversable<int, int> $both
     */
    // @phpstan-ignore missingType.parameter (untyped parameter is the point of this fixture)
    public function rename(?int $id, int|string $name, $untyped, \Countable&\Traversable $both): static
    {
        return $this;
    }

    public function noParams(): string
    {
        return '';
    }

    public function twoStrings(string $a, string $b): int
    {
        return 0;
    }

    /**
     * @param list<int> $c
     */
    public function intThenAny(int $a, string $b, array $c = []): void
    {
    }

    public function optionalTail(string $name, int $times = 1): string
    {
        return str_repeat($name, $times);
    }

    #[Audit('x')]
    public function audited(string $name): string
    {
        return $name;
    }

    #[SpecialAudit('y')]
    public function specialAudited(): void
    {
    }

    protected function internal(): void
    {
    }

    // @phpstan-ignore missingType.return (untyped return is the point of this fixture)
    public function untypedReturn()
    {
    }

    public function repo(UserRepository $self, RepositoryInterface $iface, ?AbstractRepository $maybe): self
    {
        return $this;
    }

    public function asAbstract(): AbstractRepository
    {
        return $this;
    }

    public function selfReturn(): static
    {
        return $this;
    }
}
