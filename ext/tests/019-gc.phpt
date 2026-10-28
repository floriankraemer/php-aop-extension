--TEST--
OriginalCall keeps $this alive, cycles through it are collectable, no growth over many calls
--EXTENSIONS--
aop
--INI--
opcache.jit=disable
--FILE--
<?php
class Node
{
    public $keep;
    public function __construct(public string $name) {}
    public function touch() { return $this->name; }
    public function __destruct() { echo "destruct {$this->name}\n"; }
}

\AOP\Engine::register(
    fn (array $jp) => $jp['name'] === 'touch' ? 1 : 0,
    function ($jp, ?object $self, array $args, \AOP\OriginalCall $original) {
        $self->keep = $original;    // cycle: Node -> OriginalCall -> Node
        return $original($args);
    }
);

$n = new Node('a');
$n->touch();
unset($n);
echo "unset, cycle still alive\n";
var_dump(gc_collect_cycles() > 0);
echo "after gc\n";

$held = null;
\AOP\Engine::register(fn (array $jp) => $jp['name'] === 'touch' ? 1 : 0,
    function ($jp, ?object $self, array $args, \AOP\OriginalCall $original) use (&$held) {
        $held = $original;
        return $original($args);
    });
$n = new Node('b');
$n->touch();
unset($n);
echo "held by the OriginalCall: ", $held([]), "\n";
$held = null;
echo "released\n";

\AOP\Engine::register(fn (array $jp) => $jp['name'] === 'touch' ? 1 : 0,
    fn ($jp, $self, $args, $original) => $original($args));
$n = new Node('c');
$n->touch();
$before = memory_get_usage();
for ($i = 0; $i < 10000; ++$i) {
    $n->touch();
}
var_dump(memory_get_usage() - $before < 1024);
echo "end\n";
?>
--EXPECT--
unset, cycle still alive
destruct a
bool(true)
after gc
held by the OriginalCall: b
destruct b
released
bool(true)
end
destruct c
