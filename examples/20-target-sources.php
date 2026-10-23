<?php

declare(strict_types=1);

namespace Demo\Targets;

require __DIR__.'/../vendor/autoload.php';

use AOP\TargetSource\FiberLocalTargetSource;
use AOP\TargetSource\HotSwappableTargetSource;
use AOP\TargetSource\PoolingTargetSource;
use AOP\TargetSource\PrototypeTargetSource;
use AOP\Weaver;

final class Greeting
{
    private static int $instances = 0;

    public readonly int $number;

    public function __construct(public readonly string $text)
    {
        $this->number = ++self::$instances;
    }

    public function say(): string
    {
        return "{$this->text} (#{$this->number})";
    }
}

$weaver = Weaver::fromAspects([]);
$weaver->enable();

// The caller holds a stand-in: its methods run on whatever the target source returns.
echo "--- hot-swappable\n";
$swappable = new HotSwappableTargetSource(new Greeting('hello'));
$standIn = $weaver->targetSource(Greeting::class, $swappable);
echo $standIn->say()."\n";
$swappable->swap(new Greeting('bonjour'));
echo $standIn->say()."\n";

echo "--- prototype: a new target for every call\n";
$prototype = new PrototypeTargetSource(Greeting::class, static fn (): Greeting => new Greeting('proto'));
$standIn = $weaver->targetSource(Greeting::class, $prototype);
echo $standIn->say()."\n";
echo $standIn->say()."\n";
echo 'created: '.$prototype->getCreatedCount()."\n";

echo "--- pooling: targets are borrowed for one call and returned\n";
$pool = new PoolingTargetSource(Greeting::class, static fn (): Greeting => new Greeting('pooled'), 2);
$standIn = $weaver->targetSource(Greeting::class, $pool);
echo $standIn->say()."\n";
echo $standIn->say()."\n";
$stats = $pool->stats();
echo "created {$stats->created}, borrowed {$stats->borrowed}, released {$stats->released}, idle {$stats->idle}\n";

echo "--- fiber-local: one target per fiber\n";
$local = new FiberLocalTargetSource(Greeting::class, static fn (): Greeting => new Greeting('fiber'));
$standIn = $weaver->targetSource(Greeting::class, $local);
$fiber = new \Fiber(static function () use ($standIn): void {
    echo 'in a fiber: '.$standIn->say()."\n";
});
$fiber->start();
echo 'in the main fiber: '.$standIn->say()."\n";
echo 'created: '.$local->getCreatedCount()."\n";

/* Expected output:
--- hot-swappable
hello (#1)
bonjour (#2)
--- prototype: a new target for every call
proto (#3)
proto (#4)
created: 2
--- pooling: targets are borrowed for one call and returned
pooled (#5)
pooled (#5)
created 1, borrowed 2, released 2, idle 1
--- fiber-local: one target per fiber
in a fiber: fiber (#6)
in the main fiber: fiber (#7)
created: 2
*/
