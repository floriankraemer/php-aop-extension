<?php

declare(strict_types=1);

namespace AopBench;

use AopBench\Workload\Order;
use AopBench\Workload\OrderLine;
use PhpBench\Attributes\BeforeMethods;

/** Array processing, string building and an object graph: call overhead is a smaller share of the work. */
#[BeforeMethods('assertEnvironment')]
final class MixedBench
{
    use Environment;

    public function benchOrders(): void
    {
        $total = 0;
        $length = 0;
        for ($n = 0; $n < 300; ++$n) {
            $order = new Order();
            for ($i = 0; $i < 20; ++$i) {
                $order->add(new OrderLine('sku-'.$i, ($n + $i) % 7 + 1, 100 + $i * 13));
            }
            $total += $order->totalCents();
            $length += strlen($order->summary());
        }
        if (-1 === $total + $length) {
            throw new \LogicException('unreachable');
        }
    }
}
