--TEST--
Return values: used and unused, replaced, void and typed functions
--EXTENSIONS--
aop
--INI--
; opcache would inline these trivial functions (optimizer pass 16), see 025-opcache-inlining.phpt
opcache.optimization_level=0x7FFE3FFF
--FILE--
<?php
class Destructs { public function __destruct() { echo "destructed\n"; } }
function make() { return new Destructs(); }
function nothing(): void { echo "nothing ran\n"; }
function typed(): int { return 1; }
function arr() { return [1, 2, 3]; }

\AOP\Engine::register(
    fn (array $jp) => in_array($jp['name'], ['make', 'nothing', 'typed', 'arr'], true) ? 1 : 0,
    function ($jp, $self, $args, $original) {
        $r = $original($args);
        return match ($jp['name']) {
            'typed' => $r + 41,
            'arr' => array_reverse($r),
            default => $r,
        };
    }
);

make();                // unused result is released
echo "after unused\n";
$o = make();
echo "kept\n";
unset($o);
var_dump(nothing());
var_dump(typed());
var_dump(arr());
echo implode(',', arr()), "\n";
$x = typed() + typed();
var_dump($x);
?>
--EXPECT--
destructed
after unused
kept
destructed
nothing ran
NULL
int(42)
array(3) {
  [0]=>
  int(3)
  [1]=>
  int(2)
  [2]=>
  int(1)
}
3,2,1
int(84)
