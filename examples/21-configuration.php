<?php

declare(strict_types=1);

namespace Demo\Config;

require __DIR__.'/../vendor/autoload.php';

use AOP\JoinPoint\JoinPointInterface;
use AOP\Weaver;

// A plain class: no attributes, the configuration says what it advises.
final class AuditLog
{
    public function before(JoinPointInterface $jp): void
    {
        echo "audit: {$jp->getSignature()->toShortString()}\n";
    }

    public function around(\AOP\JoinPoint\ProceedingJoinPointInterface $pjp, string $item, int $quantity): mixed
    {
        echo "audit: $quantity x $item\n";

        return $pjp->proceed();
    }
}

final class Orders
{
    public function place(string $item, int $quantity): string
    {
        return "placed $quantity x $item";
    }

    public function cancel(string $item): string
    {
        return "cancelled $item";
    }
}

$config = [
    'pointcuts' => [
        'order_operations' => 'execution(* Demo\Config\Orders::*(..))',
    ],
    'aspects' => [
        [
            'id' => 'audit',
            'class' => AuditLog::class,
            'order' => 10,
            'advice' => [
                ['type' => 'before', 'pointcut-ref' => 'order_operations', 'method' => 'before'],
                ['type' => 'around', 'pointcut' => 'execution(* Demo\Config\Orders::place(..)) && args(item, quantity)', 'method' => 'around', 'arg-names' => ['item', 'quantity']],
            ],
        ],
    ],
];

$weaver = Weaver::fromConfig($config, static fn (string $class): object => new $class());
$weaver->enable();

$orders = new Orders();
echo $orders->place('book', 2)."\n";
echo $orders->cancel('book')."\n";

echo "--- removeAspect\n";
$weaver->removeAspect(AuditLog::class);
echo $orders->place('pen', 5)."\n";

/* Expected output:
audit: 2 x book
audit: Orders::place(..)
placed 2 x book
audit: Orders::cancel(..)
cancelled book
--- removeAspect
placed 5 x pen
*/
