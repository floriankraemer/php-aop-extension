<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Targets;

class MixedMethods
{
    /** @var list<string> */
    private array $list = [];

    public function __construct()
    {
    }

    public function __toString(): string
    {
        return 'mixed';
    }

    public function plain(): string
    {
        return 'plain';
    }

    protected function guarded(): string
    {
        return 'guarded';
    }

    private function secret(): string
    {
        return 'secret';
    }

    public static function create(): self
    {
        return new self();
    }

    final public function locked(): string
    {
        return 'locked';
    }

    /**
     * @param list<string> $list
     */
    public function byRef(array &$list): void
    {
        $list[] = $this->secret();
    }

    /**
     * @return list<string>
     */
    public function &refReturn(): array
    {
        $this->list[] = 'ref';

        return $this->list;
    }

    /**
     * @param \ArrayObject<int, mixed> $o
     */
    public function objectDefault(\ArrayObject $o = new \ArrayObject()): int
    {
        return count($o);
    }
}
