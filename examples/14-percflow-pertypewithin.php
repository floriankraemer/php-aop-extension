<?php

declare(strict_types=1);

namespace Demo\Flows;

require __DIR__.'/../vendor/autoload.php';

use AOP\Aspect\AspectScope;
use AOP\Attribute\Aspect;
use AOP\Attribute\Before;
use AOP\JoinPoint\JoinPointInterface;
use AOP\Weaver;

final class Report
{
    public function line(string $text): string
    {
        return "line: $text";
    }
}

final class Batch
{
    public function __construct(private readonly Report $report)
    {
    }

    public function run(string $name): void
    {
        echo $this->report->line($name)."\n";
    }
}

// percflow: one aspect instance per entry into Batch::run(). The advice runs only inside such a flow,
// so the count restarts for every batch and lines outside a batch are not counted.
#[Aspect(scope: AspectScope::PER_CFLOW, pointcut: 'execution(* Demo\Flows\Batch::run(..))')]
final class BatchCounter
{
    private int $lines = 0;

    #[Before('execution(* Demo\Flows\Report::line(..))')]
    public function count(): void
    {
        ++$this->lines;
        echo "  counting line {$this->lines} of this batch\n";
    }
}

// pertypewithin: one aspect instance per type the join point belongs to; the pointcut is a type pattern.
#[Aspect(scope: AspectScope::PER_TYPE_WITHIN, pointcut: 'Demo\Flows\*')]
final class TypeCounter
{
    private int $calls = 0;

    #[Before('execution(* Demo\Flows\*::*(..))')]
    public function count(JoinPointInterface $jp): void
    {
        ++$this->calls;
        echo "  {$jp->getSignature()->getDeclaringTypeName()} calls so far: {$this->calls}\n";
    }
}

// percflowbelow: like percflow, but the advice does not run at the entry join point itself.
#[Aspect(scope: AspectScope::PER_CFLOW_BELOW, pointcut: 'execution(* Demo\Flows\Batch::run(..))')]
final class BelowCounter
{
    private int $calls = 0;

    #[Before('execution(* Demo\Flows\Report::line(..))')]
    public function count(): void
    {
        ++$this->calls;
        echo "  {$this->calls} call(s) below run()\n";
    }
}

$report = new Report();
$batch = new Batch($report);

echo "--- percflow\n";
$weaver = Weaver::fromAspects([new BatchCounter()]);
$weaver->enable();
$batch->run('first');
$batch->run('second');
echo $report->line('outside any batch')."\n";
$weaver->disable();

echo "--- percflowbelow\n";
$weaver = Weaver::fromAspects([new BelowCounter()]);
$weaver->enable();
$batch->run('third');
$weaver->disable();

echo "--- pertypewithin\n";
$weaver = Weaver::fromAspects([new TypeCounter()]);
$weaver->enable();
$batch->run('fourth');
$weaver->disable();

/* Expected output:
--- percflow
  counting line 1 of this batch
line: first
  counting line 1 of this batch
line: second
line: outside any batch
--- percflowbelow
  1 call(s) below run()
line: third
--- pertypewithin
  Demo\Flows\Batch calls so far: 1
  Demo\Flows\Report calls so far: 1
line: fourth
*/
