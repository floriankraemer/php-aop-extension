<?php

declare(strict_types=1);

namespace Demo\Configurable;

require __DIR__.'/../vendor/autoload.php';

use AOP\Attribute\Configurable;
use AOP\Weaver;

// Objects of this class are created with `new`, but a container configures them.
#[Configurable(service: 'mailer')]
final class Mailer
{
    public string $transport = 'not configured';

    public function __construct()
    {
        // The configurer runs after this constructor (the outermost one), so it can see the injected values.
    }

    public function send(string $to): string
    {
        return "sent to $to via {$this->transport}";
    }
}

$weaver = Weaver::fromAspects([]);
$weaver->configurable(static function (object $object, Configurable $attribute): void {
    // A real application looks the service up in its container here.
    $object->transport = "SMTP service '{$attribute->service}'";
});
$weaver->enable();

$mailer = new Mailer();
echo $mailer->send('ops@example.test')."\n";

/* Expected output:
sent to ops@example.test via SMTP service 'mailer'
*/
