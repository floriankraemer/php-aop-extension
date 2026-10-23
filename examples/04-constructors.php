<?php

declare(strict_types=1);

namespace Demo\Constructors;

require __DIR__.'/../vendor/autoload.php';

// Needs aop.interception=hook (php.ini or -d): field and initialization advice is unavailable in woven mode.

use AOP\Attribute\Aspect;
use AOP\Attribute\Before;
use AOP\JoinPoint\JoinPointInterface;
use AOP\Weaver;

class Base
{
    public function __construct(protected string $name)
    {
        echo "Base::__construct({$this->name})\n";
    }
}

final class Connection extends Base
{
    public function __construct(string $name, private readonly string $dsn)
    {
        parent::__construct($name);
        echo "Connection::__construct(dsn set)\n";
    }
}

#[Aspect]
final class ConstructionAspect
{
    // initialization() matches only the outermost constructor of an object, so the
    // parent::__construct() call above does not trigger it a second time.
    #[Before('initialization(Demo\Constructors\Base+)')]
    public function onConstruct(JoinPointInterface $jp): void
    {
        echo 'object of '.$jp->getThis()::class." is being constructed\n";
    }

    // Constructors are not overridden: this matches the constructor declared by Base, whether it runs
    // for a Base object or through parent::__construct(), and never the constructor of Connection.
    // Use Base+ to match the subclass constructors as well.
    #[Before('execution(* Demo\Constructors\Base::__construct(..))')]
    public function onBaseConstructor(JoinPointInterface $jp): void
    {
        echo 'Base constructor runs for '.$jp->getThis()::class."\n";
    }
}

$weaver = Weaver::fromAspects([new ConstructionAspect()]);
$weaver->enable();

echo "--- new Connection\n";
$connection = new Connection('primary', 'mysql:host=db');

echo "--- new Base\n";
$base = new Base('plain');

/* Expected output:
--- new Connection
object of Demo\Constructors\Connection is being constructed
Base constructor runs for Demo\Constructors\Connection
Base::__construct(primary)
Connection::__construct(dsn set)
--- new Base
object of Demo\Constructors\Base is being constructed
Base constructor runs for Demo\Constructors\Base
Base::__construct(plain)
*/
