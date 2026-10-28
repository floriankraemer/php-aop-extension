--TEST--
Destructors are join points; exit() inside the dispatcher or the original unwinds cleanly
--EXTENSIONS--
aop
--INI--
opcache.jit=disable
--FILE--
<?php
class Res
{
    public function __construct(public string $n) {}
    public function __destruct() { echo "destruct {$this->n}\n"; }
    public function stop() { echo "stopping\n"; exit(0); }
}
function quit() { return 'never'; }

register_shutdown_function(function () { echo "shutdown, registered=", var_export(\AOP\Engine::isRegistered(), true), "\n"; });
\AOP\Engine::register(
    fn (array $jp) => in_array($jp['name'], ['__destruct', 'quit', 'stop'], true) ? 1 : 0,
    function ($jp, $self, $args, $original) {
        echo 'dispatch ', $jp['name'], "\n";
        if ($jp['name'] === 'quit') {
            exit(0);
        }
        return $original($args);
    }
);
$a = new Res('a');
unset($a);
$keep = new Res('kept');
echo $keep->n, "\n";
function run() { (new Res('temp'))->stop(); }
run();
echo "not reached\n";
?>
--EXPECT--
dispatch __destruct
destruct a
kept
dispatch stop
stopping
dispatch __destruct
destruct temp
shutdown, registered=true
dispatch __destruct
destruct kept
