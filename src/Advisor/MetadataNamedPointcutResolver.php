<?php

declare(strict_types=1);

namespace AOP\Advisor;

use AOP\Aspect\AspectMetadata;
use AOP\Aspect\AspectMetadataReader;
use AOP\Exception\AspectDefinitionException;
use AOP\Exception\PointcutSyntaxException;
use AOP\Pointcut\Expression\NamedPointcutResolverInterface;

final class MetadataNamedPointcutResolver implements NamedPointcutResolverInterface
{
    /** @var array<string, AspectMetadata> */
    private array $metadata = [];

    /**
     * @param iterable<AspectMetadata> $metadata
     */
    public function __construct(iterable $metadata, private readonly AspectMetadataReader $reader = new AspectMetadataReader())
    {
        foreach ($metadata as $meta) {
            $this->metadata[$meta->aspectClass] = $meta;
        }
    }

    public function resolve(?string $className, string $methodName, string $fromAspect): string
    {
        /** @var class-string $class */
        $class = ltrim($className ?? $fromAspect, '\\');
        $meta = $this->metadata[$class] ?? null;

        if (null === $meta) {
            try {
                $meta = $this->metadata[$class] = $this->reader->read($class);
            } catch (AspectDefinitionException|\ReflectionException $e) {
                throw new PointcutSyntaxException(sprintf('Cannot resolve named pointcut "%s::%s()" referenced from %s: %s', $class, $methodName, $fromAspect, $e->getMessage()), 0, $e);
            }
        }

        return $meta->namedPointcuts[$methodName]
            ?? throw new PointcutSyntaxException(sprintf('Unknown named pointcut "%s::%s()" referenced from %s.', $class, $methodName, $fromAspect));
    }
}
