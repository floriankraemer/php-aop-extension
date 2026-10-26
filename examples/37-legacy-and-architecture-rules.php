<?php

declare(strict_types=1);

namespace Demo\Legacy;

require __DIR__.'/../vendor/autoload.php';

use AOP\Attribute\Around;
use AOP\Attribute\Aspect;
use AOP\Attribute\DeclareError;
use AOP\Attribute\DeclareWarning;
use AOP\Exception\DeclareErrorException;
use AOP\JoinPoint\ProceedingJoinPointInterface;
use AOP\Weaver;

// ---- Part A: code you cannot edit -------------------------------------------------------

// Pretend this is a vendor package: final, and not ours to change.
final class VendorMailer
{
    public function send(string $to, string $subject): string
    {
        return "mail to $to: $subject";
    }
}

// Pretend this is a legacy include file.
function legacy_tax_rate(string $country): float
{
    return 'DE' === $country ? 0.19 : 0.25;
}

function legacy_send_invoice(VendorMailer $mailer, string $customer, int $netCents): string
{
    $gross = (int) round($netCents * (1 + legacy_tax_rate('DE')));

    return $mailer->send($customer, "invoice of $gross cents");
}

#[Aspect]
final class LegacyAdapter
{
    public bool $mailEnabled = false;
    public ?float $taxOverride = null;

    // Feature flag: while the flag is off, the final vendor class never sends anything.
    #[Around('execution(* Demo\Legacy\VendorMailer::send(..))')]
    public function mailSwitch(ProceedingJoinPointInterface $pjp): mixed
    {
        if (!$this->mailEnabled) {
            return 'mail suppressed by feature flag';
        }

        return $pjp->proceed();
    }

    // Test seam: replace the result of a legacy function without touching it.
    #[Around('execution(* Demo\Legacy\legacy_tax_rate(..)) && args(country)')]
    public function fixedTax(ProceedingJoinPointInterface $pjp, string $country): mixed
    {
        return $this->taxOverride ?? $pjp->proceed();
    }
}

// ---- Part B: architecture rules, checked when the code is woven -------------------------

final class OrderRepository
{
    public function find(int $id): string
    {
        return "order $id";
    }
}

final class OrderService
{
    public function __construct(private readonly OrderRepository $repository)
    {
    }

    public function show(int $id): string
    {
        return $this->repository->find($id);
    }
}

final class OrderController
{
    public function __construct(private readonly OrderService $service, private readonly OrderRepository $repository)
    {
    }

    public function viaService(int $id): string
    {
        return $this->service->show($id);
    }

    public function shortcut(int $id): string
    {
        return $this->repository->find($id);
    }

    public function notify(VendorMailer $mailer): string
    {
        return $mailer->send('ops', 'order shipped');
    }
}

#[Aspect]
#[DeclareWarning(
    'call(* Demo\Legacy\OrderRepository::*(..)) && within(Demo\Legacy\*Controller)',
    'controllers must go through a service, not call a repository',
)]
#[DeclareError(
    'call(* Demo\Legacy\VendorMailer::*(..)) && within(Demo\Legacy\*Controller)',
    'controllers must not send mail directly',
)]
final class ArchitectureRules
{
}

set_error_handler(static function (int $severity, string $message): bool {
    echo "warning: $message\n";

    return true;
});

$adapter = new LegacyAdapter();
Weaver::fromAspects([$adapter, new ArchitectureRules()])->enable();

echo "--- A: legacy code\n";
$mailer = new VendorMailer();
echo legacy_send_invoice($mailer, 'ada', 10000)."\n";
$adapter->mailEnabled = true;
echo legacy_send_invoice($mailer, 'ada', 10000)."\n";
$adapter->taxOverride = 0.0;
echo legacy_send_invoice($mailer, 'ada', 10000)."\n";

echo "--- B: architecture rules\n";
$repository = new OrderRepository();
$controller = new OrderController(new OrderService($repository), $repository);
echo $controller->viaService(1)."\n";
echo $controller->shortcut(2)."\n";

try {
    $controller->notify($mailer);
} catch (DeclareErrorException $e) {
    echo 'error: '.$e->getMessage()."\n";
}

/* Expected output:
--- A: legacy code
mail suppressed by feature flag
mail to ada: invoice of 11900 cents
mail to ada: invoice of 10000 cents
--- B: architecture rules
order 1
warning: controllers must go through a service, not call a repository [method-call(OrderRepository::find(..)), declared by Demo\Legacy\ArchitectureRules]
order 2
error: controllers must not send mail directly [method-call(VendorMailer::send(..)), declared by Demo\Legacy\ArchitectureRules]
*/
