<?php

declare(strict_types=1);

namespace AOP\Advice;

use AOP\Aspect\AspectScope;

final readonly class AdviceDefinition
{
    /**
     * @param class-string $aspectClass
     */
    public function __construct(
        public string $aspectClass,
        public string $methodName,
        public AdviceType $type,
        public string $expression,
        public ?string $returning,
        public ?string $throwing,
        public int $declarationIndex,
        public AspectScope $scope = AspectScope::SINGLETON,
    ) {
    }
}
