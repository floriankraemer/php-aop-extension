<?php

declare(strict_types=1);

namespace AOP\Tests\Fixtures\Binding;

/**
 * Not attributed itself: `@within`/`@target` must see the attribute of the concrete subclass.
 */
class BaseService implements ServiceInterface
{
    public function inherited(): string
    {
        return 'inherited';
    }
}
