--TEST--
PHP 8.4+: advice on lazy ghosts and proxies does not initialize them; proceeding does, as without advice
--EXTENSIONS--
aop
--SKIPIF--
<?php if (PHP_VERSION_ID < 80400) die('skip PHP 8.4+ (lazy objects)'); ?>
--FILE--
<?php
require __DIR__.'/000-helper.inc';

class Repo
{
    public array $rows;
    public function __construct() { echo "Repo::__construct\n"; $this->rows = ['a', 'b']; }
    public function count() { return count($this->rows); }
    public function name() { return 'repo'; }
}

$r = new ReflectionClass(Repo::class);
\AOP\Engine::register(
    fn (array $jp) => $jp['class'] === 'Repo' ? 1 : 0,
    function (array $jp, ?object $self, array $args, \AOP\OriginalCall $original) use ($r) {
        echo '> ', jp_name($jp), ' lazy=', var_export($self && $r->isUninitializedLazyObject($self), true), "\n";
        $result = $original($args);
        echo '< ', jp_name($jp), ' lazy=', var_export($self && $r->isUninitializedLazyObject($self), true), "\n";
        return $result;
    }
);

echo "-- ghost\n";
$ghost = $r->newLazyGhost(function (Repo $o) { echo "init ghost\n"; $o->__construct(); });
var_dump($ghost->name());          // no property access: stays lazy
var_dump($r->isUninitializedLazyObject($ghost));
var_dump($ghost->count());         // property access in the original initializes it
var_dump($r->isUninitializedLazyObject($ghost));

echo "-- proxy\n";
// The real instance comes from `new`, so it is wrapped and its constructor's write is a field-set join point;
// the proxy is not (no `new`), and the engine forwards its property accesses to the instance's std handlers.
$proxy = $r->newLazyProxy(function (Repo $o) { echo "init proxy\n"; return new Repo(); });
var_dump($proxy->count());
var_dump($r->isUninitializedLazyObject($proxy));
?>
--EXPECT--
-- ghost
> Repo::name lazy=true
< Repo::name lazy=true
string(4) "repo"
bool(true)
> Repo::count lazy=true
init ghost
> Repo::__construct lazy=false
Repo::__construct
< Repo::__construct lazy=false
< Repo::count lazy=false
int(2)
bool(false)
-- proxy
> Repo::count lazy=true
init proxy
> Repo::__construct lazy=false
Repo::__construct
> Repo::rows lazy=false
< Repo::rows lazy=false
< Repo::__construct lazy=false
< Repo::count lazy=false
int(2)
bool(false)
