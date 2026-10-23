<?php

declare(strict_types=1);

namespace Demo\AdviceExecution;

require __DIR__.'/../vendor/autoload.php';

use AOP\Attribute\Aspect;
use AOP\Attribute\Before;
use AOP\JoinPoint\JoinPointInterface;
use AOP\Weaver;

final class Warehouse
{
    public function ship(string $item): string
    {
        return "shipped $item";
    }
}

#[Aspect]
final class ShipmentLog
{
    #[Before('execution(* Demo\AdviceExecution\Warehouse::ship(..))')]
    public function beforeShip(JoinPointInterface $jp): void
    {
        echo "advice runs for {$jp->getSignature()->toShortString()}\n";
    }

    // adviceexecution() matches the execution of the advice methods themselves.
    #[Before('adviceexecution() && within(Demo\AdviceExecution\ShipmentLog)')]
    public function traceAdvice(JoinPointInterface $jp): void
    {
        echo "  adviceexecution join point: {$jp->getSignature()->toShortString()}\n";
    }
}

$weaver = Weaver::fromAspects([new ShipmentLog()]);
$weaver->enable();

echo (new Warehouse())->ship('crate')."\n";

/* Expected output:
  adviceexecution join point: adviceexecution(ShipmentLog::beforeShip(..))
advice runs for Warehouse::ship(..)
shipped crate
*/
