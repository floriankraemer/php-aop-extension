<?php

declare(strict_types=1);

namespace Demo\Tx;

require __DIR__.'/../vendor/autoload.php';

use AOP\Attribute\Around;
use AOP\Attribute\Aspect;
use AOP\JoinPoint\ProceedingJoinPointInterface;
use AOP\Weaver;

// Marks a method that must run inside a database transaction.
#[\Attribute(\Attribute::TARGET_METHOD)]
final class Transactional
{
}

// An in-memory stand-in for a database connection: it only records what happened.
final class FakeConnection
{
    public function begin(): void
    {
        echo "  [db] BEGIN\n";
    }

    public function commit(): void
    {
        echo "  [db] COMMIT\n";
    }

    public function rollback(): void
    {
        echo "  [db] ROLLBACK\n";
    }
}

final class InventoryService
{
    /** @var array<string, int> */
    public array $stock = ['book' => 5];

    #[Transactional]
    public function reserve(string $sku, int $quantity): void
    {
        if (($this->stock[$sku] ?? 0) < $quantity) {
            throw new \DomainException("not enough $sku in stock");
        }
        $this->stock[$sku] -= $quantity;
        echo "  reserved $quantity x $sku\n";
    }
}

final class PaymentService
{
    #[Transactional]
    public function charge(int $cents): void
    {
        if ($cents > 5000) {
            throw new \RuntimeException('card declined');
        }
        echo "  charged $cents cents\n";
    }
}

final class OrderService
{
    public function __construct(private InventoryService $inventory, private PaymentService $payments)
    {
    }

    #[Transactional]
    public function placeOrder(string $sku, int $quantity, int $cents): string
    {
        // Both calls are transactional too: they join the transaction opened here.
        $this->inventory->reserve($sku, $quantity);
        $this->payments->charge($cents);

        return "order for $quantity x $sku";
    }
}

#[Aspect]
final class TransactionAspect
{
    private int $depth = 0;

    public function __construct(private readonly FakeConnection $connection)
    {
    }

    #[Around('execution(* *(..)) && @annotation(Demo\Tx\Transactional)')]
    public function transactional(ProceedingJoinPointInterface $pjp): mixed
    {
        // Only the outermost call owns the transaction; nested calls join it.
        $outermost = 0 === $this->depth;
        if ($outermost) {
            $this->connection->begin();
        }

        ++$this->depth;
        try {
            $result = $pjp->proceed();
        } catch (\Throwable $e) {
            if ($outermost) {
                $this->connection->rollback();
            }

            throw $e;
        } finally {
            --$this->depth;
        }

        if ($outermost) {
            $this->connection->commit();
        }

        return $result;
    }
}

Weaver::fromAspects([new TransactionAspect(new FakeConnection())])->enable();

$inventory = new InventoryService();
$orders = new OrderService($inventory, new PaymentService());

function attempt(string $title, callable $work): void
{
    echo "$title\n";
    try {
        echo '  => '.$work()."\n";
    } catch (\Throwable $e) {
        echo '  => failed: '.$e->getMessage()."\n";
    }
}

attempt('1. success: one transaction around the whole order', fn () => $orders->placeOrder('book', 1, 1999));
attempt('2. payment throws: the outer transaction rolls back', fn () => $orders->placeOrder('book', 1, 9999));
attempt('3. inventory throws: nothing else runs', fn () => $orders->placeOrder('book', 9, 1999));
attempt('4. a lone transactional call gets its own transaction', function () use ($inventory) {
    $inventory->reserve('book', 1);

    return 'ok';
});

/* Expected output:
1. success: one transaction around the whole order
  [db] BEGIN
  reserved 1 x book
  charged 1999 cents
  [db] COMMIT
  => order for 1 x book
2. payment throws: the outer transaction rolls back
  [db] BEGIN
  reserved 1 x book
  [db] ROLLBACK
  => failed: card declined
3. inventory throws: nothing else runs
  [db] BEGIN
  [db] ROLLBACK
  => failed: not enough book in stock
4. a lone transactional call gets its own transaction
  [db] BEGIN
  reserved 1 x book
  [db] COMMIT
  => ok
*/
