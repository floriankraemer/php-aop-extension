<?php

declare(strict_types=1);

namespace AOP\Advisor\Api;

use AOP\Advisor\Advisor;
use AOP\Pointcut\Api\ExpressionPointcut;
use AOP\Pointcut\PointcutInterface;

/**
 * Any pointcut with any advice the adapter registry understands (Spring's DefaultPointcutAdvisor).
 *
 *     $weaver->addAdvisor((new DefaultPointcutAdvisor('execution(* App\Service\**::*(..))', new MyAdvice()))->toAdvisor());
 *
 * A string pointcut is compiled as an expression. The id (default `<advice class>#<n>`) names the advisor in
 * `Weaver::removeAdvisor()` and is the tie-break of the advisor order.
 */
class DefaultPointcutAdvisor implements PointcutAdvisorInterface
{
    private static int $sequence = 0;

    private readonly PointcutInterface $pointcut;

    private readonly string $id;

    private ?Advisor $advisor = null;

    public function __construct(
        PointcutInterface|string $pointcut,
        private readonly object $advice,
        private readonly int $order = PHP_INT_MAX,
        ?string $id = null,
        private readonly AdvisorAdapterRegistry $adapters = new AdvisorAdapterRegistry(),
    ) {
        $this->pointcut = $pointcut instanceof PointcutInterface ? $pointcut : new ExpressionPointcut($pointcut);
        $this->id = $id ?? sprintf('%s#%d', get_debug_type($advice), ++self::$sequence);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getOrder(): int
    {
        return $this->order;
    }

    public function getPointcut(): PointcutInterface
    {
        return $this->pointcut;
    }

    public function getAdvice(): object
    {
        return $this->advice;
    }

    /**
     * @throws UnknownAdviceTypeException when no adapter supports the advice
     */
    public function toAdvisor(): Advisor
    {
        return $this->advisor ??= new Advisor(
            $this->id,
            null,
            $this->order,
            $this->pointcut,
            $this->adapters->getInterceptor($this->advice),
            $this->adapters->adviceType($this->advice),
        );
    }
}
