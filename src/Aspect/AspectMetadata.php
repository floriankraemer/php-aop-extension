<?php

declare(strict_types=1);

namespace AOP\Aspect;

use AOP\Advice\AdviceDefinition;

final readonly class AspectMetadata
{
    /**
     * @param class-string                 $aspectClass
     * @param array<string, string>        $namedPointcuts method name => expression
     * @param list<AdviceDefinition>       $advices        in declaration order
     * @param list<IntroductionDefinition> $introductions  in declaration order
     */
    public function __construct(
        public string $aspectClass,
        public int $order,
        public array $namedPointcuts,
        public array $advices,
        public array $introductions = [],
        public AspectScope $scope = AspectScope::SINGLETON,
    ) {
    }
}
