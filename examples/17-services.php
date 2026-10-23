<?php

declare(strict_types=1);

namespace Demo\Services;

require __DIR__.'/../vendor/autoload.php';

use AOP\Attribute\Aspect;
use AOP\Attribute\Before;
use AOP\JoinPoint\JoinPointInterface;
use AOP\Weaver;

final class OrderRepository
{
    public function save(string $order): string
    {
        return "saved $order";
    }
}

final class Mailer
{
    public function send(string $to): string
    {
        return "mail to $to";
    }
}

#[Aspect]
final class ServiceAspect
{
    // service(name) matches the join points of the object registered under that name.
    #[Before('service(orders.repository) && execution(* Demo\Services\OrderRepository::save(..))')]
    public function audit(JoinPointInterface $jp): void
    {
        echo "audit: {$jp->getArgs()[0]}\n";
    }

    // Every service whose name starts with "mail" (the registered class, for every instance of it).
    #[Before('service(mail*) && execution(* Demo\Services\Mailer::send(..))')]
    public function mailLog(): void
    {
        echo "mail service called\n";
    }
}

$weaver = Weaver::fromAspects([new ServiceAspect()]);
$archive = new OrderRepository();
$weaver->registerService($archive, 'orders.repository');
$weaver->registerServiceClass(Mailer::class, 'mailer');
$weaver->enable();

echo $archive->save('order-1')."\n";
echo (new OrderRepository())->save('order-2')."\n";
echo (new Mailer())->send('ops@example.test')."\n";

/* Expected output:
audit: order-1
saved order-1
saved order-2
mail service called
mail to ops@example.test
*/
