<?php

declare(strict_types=1);

namespace Demo\Authz;

require __DIR__.'/../vendor/autoload.php';

use AOP\Attribute\Aspect;
use AOP\Attribute\Before;
use AOP\Attribute\Order;
use AOP\Attribute\Pointcut;
use AOP\JoinPoint\JoinPointInterface;
use AOP\JoinPoint\MethodSignature;
use AOP\Weaver;

// On a class it is the default for every public method, on a method it overrides the class.
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final class RequiresRole
{
    public function __construct(public readonly string $role)
    {
    }
}

final class AccessDenied extends \RuntimeException
{
}

// The security context: who is calling right now.
final class CurrentUser
{
    /** @param list<string> $roles */
    public function __construct(public string $name = 'anonymous', public array $roles = [])
    {
    }
}

#[RequiresRole('admin')]
final class AccountService
{
    public function close(int $accountId): string
    {
        return "account $accountId closed";
    }

    public function freeze(int $accountId): string
    {
        return "account $accountId frozen";
    }

    // The method-level attribute wins over the class-level default.
    #[RequiresRole('support')]
    public function lookup(int $accountId): string
    {
        return "account $accountId: active";
    }
}

final class ReportService
{
    #[RequiresRole('auditor')]
    public function yearly(): string
    {
        return 'yearly report';
    }

    // No attribute: anybody may call it, no advice is woven in.
    public function publicStats(): string
    {
        return 'public stats';
    }
}

#[Aspect]
final class Guarded
{
    // Methods that carry the attribute, or whose class does.
    #[Pointcut('execution(public * Demo\Authz\*::*(..)) && (@annotation(Demo\Authz\RequiresRole) || @within(Demo\Authz\RequiresRole))')]
    public function secured(): void
    {
    }
}

// Order(1): outermost, so the check runs before any other advice and a denied call stops here.
#[Aspect]
#[Order(1)]
final class AuthorizationAspect
{
    public function __construct(private readonly CurrentUser $user)
    {
    }

    #[Before('Demo\Authz\Guarded::secured()')]
    public function check(JoinPointInterface $jp): void
    {
        $signature = $jp->getSignature();
        \assert($signature instanceof MethodSignature);
        $method = $signature->method;
        $required = ([] !== $method->getAttributes(RequiresRole::class) ? $method : $method->getDeclaringClass())
            ->getAttributes(RequiresRole::class)[0]->newInstance()->role;

        if (!\in_array($required, $this->user->roles, true)) {
            throw new AccessDenied("{$this->user->name} needs role '$required' for {$signature->toShortString()}");
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

    #[Before('Demo\Authz\Guarded::secured()')]
    public function record(JoinPointInterface $jp): void
    {
        echo "  audit: {$this->user->name} executes {$jp->getSignature()->toShortString()}\n";
    }
}

$user = new CurrentUser();
// Order is given by #[Order], not by the list order.
Weaver::fromAspects([new AuditAspect($user), new AuthorizationAspect($user)])->enable();

$accounts = new AccountService();
$reports = new ReportService();

$attempt = static function (string $label, callable $call): void {
    try {
        echo "  $label -> {$call()}\n";
    } catch (AccessDenied $e) {
        echo "  DENIED: {$e->getMessage()}\n";
    }
};

foreach ([
    ['anonymous', []],
    ['sam', ['support']],
    ['ada', ['admin', 'auditor']],
] as [$name, $roles]) {
    $user->name = $name;
    $user->roles = $roles;
    echo "$name [".implode(', ', $roles)."]\n";
    $attempt('close(7)', static fn () => $accounts->close(7));
    $attempt('lookup(7)', static fn () => $accounts->lookup(7));
    $attempt('yearly()', static fn () => $reports->yearly());
    $attempt('publicStats()', static fn () => $reports->publicStats());
}

/* Expected output:
anonymous []
  DENIED: anonymous needs role 'admin' for AccountService::close(..)
  DENIED: anonymous needs role 'support' for AccountService::lookup(..)
  DENIED: anonymous needs role 'auditor' for ReportService::yearly(..)
  publicStats() -> public stats
sam [support]
  DENIED: sam needs role 'admin' for AccountService::close(..)
  audit: sam executes AccountService::lookup(..)
  lookup(7) -> account 7: active
  DENIED: sam needs role 'auditor' for ReportService::yearly(..)
  publicStats() -> public stats
ada [admin, auditor]
  audit: ada executes AccountService::close(..)
  close(7) -> account 7 closed
  DENIED: ada needs role 'support' for AccountService::lookup(..)
  audit: ada executes ReportService::yearly(..)
  yearly() -> yearly report
  publicStats() -> public stats
*/
