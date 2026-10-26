<?php

declare(strict_types=1);

namespace Demo\Tracing;

require __DIR__.'/../vendor/autoload.php';

use AOP\Attribute\Around;
use AOP\Attribute\Aspect;
use AOP\JoinPoint\ProceedingJoinPointInterface;
use AOP\Weaver;

// A fake clock: every reading advances by one millisecond, so durations are deterministic.
final class FakeClock
{
    private int $ms = 0;

    public function now(): int
    {
        return $this->ms++;
    }
}

final class Span
{
    /** @var list<Span> */
    public array $children = [];
    public int $end = 0;
    public ?string $error = null;

    public function __construct(public readonly string $name, public readonly int $start)
    {
    }
}

final class MetricsRegistry
{
    /** @var array<string, array{calls: int, ms: int}> */
    public array $methods = [];

    public function record(string $name, int $ms): void
    {
        $this->methods[$name] ??= ['calls' => 0, 'ms' => 0];
        ++$this->methods[$name]['calls'];
        $this->methods[$name]['ms'] += $ms;
    }
}

final class NotFound extends \RuntimeException
{
}

final class ArticleRepository
{
    public function find(int $id): string
    {
        return match ($id) {
            1 => 'Hello AOP',
            default => throw new NotFound("article $id does not exist"),
        };
    }
}

final class ArticleService
{
    public function __construct(private readonly ArticleRepository $repository)
    {
    }

    public function load(int $id): string
    {
        return $this->repository->find($id);
    }

    public function render(int $id): string
    {
        $title = $this->load($id);

        return $title."\n".str_repeat('=', strlen($title));
    }
}

final class ArticleController
{
    public function __construct(private readonly ArticleService $service)
    {
    }

    public function show(int $id): string
    {
        return $this->service->render($id);
    }
}

#[Aspect]
final class TracingAspect
{
    /** @var list<Span> */
    public array $roots = [];

    /** @var list<Span> the spans currently open, innermost last */
    private array $open = [];

    public function __construct(private readonly FakeClock $clock, private readonly MetricsRegistry $metrics)
    {
    }

    // Own span stack instead of cflow(): the advice needs the parent span object to nest the new
    // one under it, and cflow() only answers "is some other join point on the stack".
    // The pointcut covers the application methods (not constructors) and the internal function
    // str_repeat(). The indentation below uses str_pad() so the tracer does not trace itself.
    #[Around('(execution(* Demo\Tracing\Article*::*(..)) && !execution(* Demo\Tracing\*::__construct(..))) || execution(* str_repeat(..))')]
    public function span(ProceedingJoinPointInterface $pjp): mixed
    {
        $span = new Span($pjp->getSignature()->toShortString(), $this->clock->now());
        if ([] === $this->open) {
            $this->roots[] = $span;
        } else {
            $this->open[array_key_last($this->open)]->children[] = $span;
        }
        $this->open[] = $span;

        try {
            return $pjp->proceed();
        } catch (\Throwable $e) {
            $span->error = $e::class.': '.$e->getMessage();

            throw $e;
        } finally {
            array_pop($this->open);
            $span->end = $this->clock->now();
            $this->metrics->record($span->name, $span->end - $span->start);
        }
    }

    public function print(Span $span, int $depth = 0): void
    {
        echo str_pad('', $depth * 2)."{$span->name} ".($span->end - $span->start).'ms'
            .(null === $span->error ? '' : " ERROR {$span->error}")."\n";
        foreach ($span->children as $child) {
            $this->print($child, $depth + 1);
        }
    }
}

$metrics = new MetricsRegistry();
$tracing = new TracingAspect(new FakeClock(), $metrics);
Weaver::fromAspects([$tracing])->enable();

$controller = new ArticleController(new ArticleService(new ArticleRepository()));

echo $controller->show(1)."\n";
try {
    $controller->show(2);
} catch (NotFound) {
    // The spans carry the failure; the exception still reached the caller.
}

echo "--- spans\n";
foreach ($tracing->roots as $root) {
    $tracing->print($root);
}

echo "--- metrics\n";
ksort($metrics->methods);
foreach ($metrics->methods as $name => $m) {
    echo "$name calls={$m['calls']} total={$m['ms']}ms\n";
}

/* Expected output:
Hello AOP
=========
--- spans
ArticleController::show(..) 9ms
  ArticleService::render(..) 7ms
    ArticleService::load(..) 3ms
      ArticleRepository::find(..) 1ms
    str_repeat(..) 1ms
ArticleController::show(..) 7ms ERROR Demo\Tracing\NotFound: article 2 does not exist
  ArticleService::render(..) 5ms ERROR Demo\Tracing\NotFound: article 2 does not exist
    ArticleService::load(..) 3ms ERROR Demo\Tracing\NotFound: article 2 does not exist
      ArticleRepository::find(..) 1ms ERROR Demo\Tracing\NotFound: article 2 does not exist
--- metrics
ArticleController::show(..) calls=2 total=16ms
ArticleRepository::find(..) calls=2 total=2ms
ArticleService::load(..) calls=2 total=6ms
ArticleService::render(..) calls=2 total=12ms
str_repeat(..) calls=1 total=1ms
*/
