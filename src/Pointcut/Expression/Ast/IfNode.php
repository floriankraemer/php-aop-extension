<?php

declare(strict_types=1);

namespace AOP\Pointcut\Expression\Ast;

/**
 * if(Class::method()) or if(method()); a null class means the aspect declaring the advice.
 */
final readonly class IfNode implements NodeInterface
{
    public function __construct(public ?string $className, public string $methodName)
    {
    }
}
