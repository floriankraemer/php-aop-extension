<?php

declare(strict_types=1);

namespace AOP\Advisor;

use AOP\Advice\AdviceDefinition;
use AOP\Advice\AdviceType;
use AOP\Exception\AspectDefinitionException;
use AOP\Interceptor\MethodInterceptorInterface;
use AOP\Pointcut\PointcutInterface;

/**
 * Aspect-declared (`$advice`) or programmatic (`$interceptor`): exactly one of the two is set.
 */
final readonly class Advisor
{
    /**
     * @param string      $id   AspectClass::method, or a caller-chosen id for programmatic advisors
     * @param ?AdviceType $type ordering slot of a programmatic advisor among the advisors of its source; defaults to AROUND
     *
     * @throws AspectDefinitionException when neither or both of $advice and $interceptor are given
     */
    public function __construct(
        public string $id,
        public ?AdviceDefinition $advice,
        public int $order,
        public PointcutInterface $pointcut,
        public ?MethodInterceptorInterface $interceptor = null,
        public ?AdviceType $type = null,
    ) {
        if ((null === $advice) === (null === $interceptor)) {
            throw new AspectDefinitionException(sprintf('Advisor "%s" needs exactly one of an advice definition and an interceptor.', $id));
        }
    }

    /**
     * The aspect class, or the id of a programmatic advisor: the unit advisors are grouped by when ordering.
     */
    public function source(): string
    {
        return $this->advice->aspectClass ?? $this->id;
    }

    public function adviceType(): AdviceType
    {
        return $this->advice->type ?? $this->type ?? AdviceType::AROUND;
    }
}
