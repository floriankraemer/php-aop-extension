<?php

declare(strict_types=1);

namespace Demo\Flow;

require __DIR__.'/../vendor/autoload.php';

use AOP\Attribute\Aspect;
use AOP\Attribute\Before;
use AOP\JoinPoint\JoinPointInterface;
use AOP\Weaver;

final class Repository
{
    public function save(string $row): string
    {
        return "saved $row";
    }
}

final class Importer
{
    public function __construct(private readonly Repository $repository)
    {
    }

    public function import(array $rows): array
    {
        $saved = [];
        foreach ($rows as $row) {
            $saved[] = $this->repository->save($row);
        }

        return $saved;
    }
}

#[Aspect]
final class TransactionTraceAspect
{
    // cflow(p): the join point runs while p is somewhere on the call stack.
    #[Before('execution(* Demo\Flow\Repository::save(..)) && cflow(execution(* Demo\Flow\Importer::import(..)))')]
    public function insideImport(JoinPointInterface $jp): void
    {
        echo 'save() inside an import: '.$jp->getArgs()[0]."\n";
    }

    // cflow(p) counts the entry join point itself: import() is inside the flow of import().
    #[Before('execution(* Demo\Flow\Importer::import(..)) && cflow(execution(* Demo\Flow\Importer::import(..)))')]
    public function entryCounts(): void
    {
        echo "cflow: import() is inside the flow of import()\n";
    }

    // cflowbelow(p) does not: the entry join point is not below itself, so this advice never runs.
    #[Before('execution(* Demo\Flow\Importer::import(..)) && cflowbelow(execution(* Demo\Flow\Importer::import(..)))')]
    public function entryBelow(): void
    {
        echo "cflowbelow matched the entry point\n";
    }
}

$weaver = Weaver::fromAspects([new TransactionTraceAspect()]);
$weaver->enable();

$repository = new Repository();
echo $repository->save('direct')."\n";

$importer = new Importer($repository);
print_r($importer->import(['a', 'b']));

/* Expected output:
saved direct
cflow: import() is inside the flow of import()
save() inside an import: a
save() inside an import: b
Array
(
    [0] => saved a
    [1] => saved b
)
*/
