<?php

declare(strict_types=1);

namespace Demo\PerInstance;

require __DIR__.'/../vendor/autoload.php';

use AOP\Advice\Api\MethodBeforeAdviceInterface;
use AOP\Advisor\Api\DefaultPointcutAdvisor;
use AOP\JoinPoint\JoinPointInterface;
use AOP\Weaver;

final class Mailbox
{
    public function __construct(public readonly string $owner)
    {
    }

    public function deliver(string $message): string
    {
        return "{$this->owner} received: $message";
    }
}

final class Announce implements MethodBeforeAdviceInterface
{
    public function __construct(private readonly string $label)
    {
    }

    public function before(JoinPointInterface $joinPoint): void
    {
        echo "[{$this->label}] about to deliver\n";
    }
}

$weaver = Weaver::fromAspects([]);
$weaver->enable();

$alice = new Mailbox('alice');
$bob = new Mailbox('bob');

// advised() returns the advice for this one object. Other objects of the class are not affected.
$advised = $weaver->advised($alice);
$advised->addAdvisor((new DefaultPointcutAdvisor('execution(* Demo\PerInstance\Mailbox::deliver(..))', new Announce('watch'), 0, 'watch-alice'))->toAdvisor());

echo $alice->deliver('hello')."\n";
echo $bob->deliver('hi')."\n";

echo "--- after removeAdvisor\n";
$advised->removeAdvisor('watch-alice');
echo $alice->deliver('again')."\n";

/* Expected output:
[watch] about to deliver
alice received: hello
bob received: hi
--- after removeAdvisor
alice received: again
*/
