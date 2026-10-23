<?php

declare(strict_types=1);

namespace AOP\Config;

use AOP\Exception\PointcutSyntaxException;
use AOP\Pointcut\Expression\NamedPointcutResolverInterface;

/**
 * Resolves `name()` references against the top-level `pointcuts` of a configuration.
 *
 * @internal
 */
final readonly class ArrayNamedPointcutResolver implements NamedPointcutResolverInterface
{
    /**
     * @param array<string, string> $pointcuts
     */
    public function __construct(private array $pointcuts)
    {
    }

    public function resolve(?string $className, string $methodName, string $fromAspect): string
    {
        if (null !== $className) {
            throw new PointcutSyntaxException(sprintf('Cannot resolve "%s::%s()": configuration advisors can only reference the top-level named pointcuts.', $className, $methodName));
        }

        return $this->pointcuts[$methodName] ?? throw new PointcutSyntaxException(sprintf('Unknown named pointcut "%s()".', $methodName));
    }
}
