<?php

declare(strict_types=1);

namespace Demo\Stack;

require __DIR__.'/../vendor/autoload.php';

use AOP\Attribute\AfterReturning;
use AOP\Attribute\AfterThrowing;
use AOP\Attribute\Around;
use AOP\Attribute\Aspect;
use AOP\Attribute\Before;
use AOP\Attribute\Order;
use AOP\JoinPoint\JoinPointInterface;
use AOP\JoinPoint\MethodSignature;
use AOP\JoinPoint\ProceedingJoinPointInterface;
use AOP\Weaver;

#[\Attribute(\Attribute::TARGET_METHOD)]
final class RequiresRole
{
    public function __construct(public readonly string $role)
    {
    }
}

final class AccessDenied extends \RuntimeException
{
}

final class CurrentUser
{
    /** @param list<string> $roles */
    public function __construct(public string $name, public array $roles)
    {
    }
}

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

final class OrderRepository
{
    public function save(string $sku, int $cents): void
    {
        echo "  [repo] saved $sku ($cents cents)\n";
    }
}

// The application code knows nothing about roles, audit lines or transactions.
final class OrderService
{
    public function __construct(private readonly OrderRepository $orders)
    {
    }

    #[RequiresRole('sales')]
    public function place(string $sku, int $cents): string
    {
        $this->orders->save($sku, $cents);
        if ($cents > 5000) {
            throw new \DomainException('payment limit exceeded');
        }

        return "placed $sku";
    }

    #[RequiresRole('manager')]
    public function cancel(string $sku): string
    {
        return "cancelled $sku";
    }
}

// Why this order:
// 1. Authorization is outermost: deny before any work, so a rejected call leaves no audit line and no transaction.
// 2. Audit sits inside it: it records what was actually attempted, and sees the outcome after the rollback.
// 3. The transaction is innermost: it wraps only the method body, so BEGIN/COMMIT/ROLLBACK stay as short as possible.
const ORDER_METHODS = 'execution(public * Demo\Stack\OrderService::*(..)) && @annotation(Demo\Stack\RequiresRole)';

#[Aspect]
#[Order(1)]
final class AuthorizationAspect
{
    public function __construct(private readonly CurrentUser $user)
    {
    }

    #[Before(ORDER_METHODS)]
    public function check(JoinPointInterface $jp): void
    {
        $signature = $jp->getSignature();
        \assert($signature instanceof MethodSignature);
        $role = $signature->method->getAttributes(RequiresRole::class)[0]->newInstance()->role;
        if (!\in_array($role, $this->user->roles, true)) {
            throw new AccessDenied("{$this->user->name} needs role '$role'");
        }
    }
}

#[Aspect]
#[Order(2)]
final class AuditAspect
{
    public function __construct(private readonly CurrentUser $user)
    {
    }

    #[Before(ORDER_METHODS)]
    public function attempted(JoinPointInterface $jp): void
    {
        echo "  audit: {$this->user->name} -> {$jp->getSignature()->toShortString()} ".json_encode($jp->getArgs())."\n";
    }

    #[AfterReturning(pointcut: ORDER_METHODS, returning: 'result')]
    public function succeeded(JoinPointInterface $jp, mixed $result): void
    {
        echo "  audit: ok, $result\n";
    }

    #[AfterThrowing(pointcut: ORDER_METHODS, throwing: 'e')]
    public function failed(JoinPointInterface $jp, \Throwable $e): void
    {
        echo "  audit: FAILED, {$e->getMessage()}\n";
    }
}

#[Aspect]
#[Order(3)]
final class TransactionAspect
{
    public function __construct(private readonly FakeConnection $connection)
    {
    }

    #[Around(ORDER_METHODS)]
    public function transactional(ProceedingJoinPointInterface $pjp): mixed
    {
        $this->connection->begin();
        try {
            $result = $pjp->proceed();
        } catch (\Throwable $e) {
            $this->connection->rollback();

            throw $e;
        }
        $this->connection->commit();

        return $result;
    }
}

$user = new CurrentUser('sam', ['sales']);
Weaver::fromAspects([
    new TransactionAspect(new FakeConnection()),
    new AuditAspect($user),
    new AuthorizationAspect($user),
])->enable();

$service = new OrderService(new OrderRepository());

$attempt = static function (string $title, callable $call): void {
    echo "$title\n";
    try {
        echo "  => {$call()}\n";
    } catch (\Throwable $e) {
        echo '  => '.$e::class.': '.$e->getMessage()."\n";
    }
};

$attempt('1. allowed: commits', fn () => $service->place('book', 1999));
$attempt('2. unauthorized: denied before audit and transaction', fn () => $service->cancel('book'));
$attempt('3. failing order: rolled back, audited as failed', fn () => $service->place('piano', 9999));

/* Expected output:
1. allowed: commits
  audit: sam -> OrderService::place(..) ["book",1999]
  [db] BEGIN
  [repo] saved book (1999 cents)
  [db] COMMIT
  audit: ok, placed book
  => placed book
2. unauthorized: denied before audit and transaction
  => Demo\Stack\AccessDenied: sam needs role 'manager'
3. failing order: rolled back, audited as failed
  audit: sam -> OrderService::place(..) ["piano",9999]
  [db] BEGIN
  [repo] saved piano (9999 cents)
  [db] ROLLBACK
  audit: FAILED, payment limit exceeded
  => DomainException: payment limit exceeded
*/
