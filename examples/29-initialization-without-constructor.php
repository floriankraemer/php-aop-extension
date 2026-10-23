<?php

declare(strict_types=1);

namespace Demo\Init;

require __DIR__.'/../vendor/autoload.php';

// Needs aop.interception=hook (php.ini or -d): field and initialization advice is unavailable in woven mode.

use AOP\Attribute\Aspect;
use AOP\Attribute\Before;
use AOP\Attribute\Configurable;
use AOP\JoinPoint\JoinPointInterface;
use AOP\Weaver;

// No constructor: `new Clock()` creates the object without any constructor call.
final class Clock
{
    public string $zone = 'UTC';

    public function now(): string
    {
        return "12:00 {$this->zone}";
    }
}

// Configured by the container, although the application creates it with `new`.
#[Configurable(service: 'mailer')]
final class Mailer
{
    public string $transport = 'none';

    public function send(string $to): string
    {
        return "sent to $to via {$this->transport}";
    }
}

#[Aspect]
final class Lifecycle
{
    // initialization() matches the creation of a class without a constructor.
    // $this is the new object, and the advice runs before anything else the caller does with it.
    #[Before('initialization(Demo\Init\Clock)')]
    public function created(JoinPointInterface $jp): void
    {
        echo '  created '.$jp->getThis()::class."\n";
    }
}

$weaver = Weaver::fromAspects([new Lifecycle()]);
$weaver->configurable(static function (object $object, Configurable $attribute): void {
    echo '  configure '.$object::class." for service '{$attribute->service}'\n";
    $object->transport = 'SMTP';
});
$weaver->enable();

$clock = new Clock();
echo $clock->now()."\n";

$mailer = new Mailer();
echo $mailer->send('ops@example.test')."\n";

/* Expected output:
  created Demo\Init\Clock
12:00 UTC
  configure Demo\Init\Mailer for service 'mailer'
sent to ops@example.test via SMTP
*/
