--TEST--
By-reference parameters write through to the caller
--EXTENSIONS--
aop
--INI--
opcache.jit=disable
--FILE--
<?php
require __DIR__.'/000-helper.inc';

function inc(&$x, $by = 1) { $x += $by; return $x; }
function fill(array &$a, ...$values) { foreach ($values as $v) { $a[] = $v; } }
class Box { public function swap(&$a, &$b) { [$a, $b] = [$b, $a]; } }

advise(['inc', 'fill', 'Box::swap', 'sort', 'preg_match']);

$n = 1;
inc($n);
inc($n, 5);
var_dump($n);

$list = [3];
fill($list, 2, 1);
var_dump($list);

$a = 'a'; $b = 'b';
(new Box())->swap($a, $b);
var_dump($a, $b);

sort($list);
var_dump($list);
preg_match('/(\d+)/', 'abc 42', $m);
var_dump($m);

echo "-- dispatcher writes through \$args\n";
\AOP\Engine::register(fn ($jp) => $jp['name'] === 'inc' ? 1 : 0, function ($jp, $self, array $args, $original) {
    $args[0] = 100;           // writes the caller's variable: $args[0] is a reference
    $args[1] = 1;             // by-value parameter: no effect on the caller
    return $original($args);
});
$n = 0; $by = 7;
var_dump(inc($n, $by), $n, $by);
?>
--EXPECT--
> inc(1)
< inc = 2
> inc(2, 5)
< inc = 7
int(7)
> fill([3], 2, 1)
< fill = NULL
array(3) {
  [0]=>
  int(3)
  [1]=>
  int(2)
  [2]=>
  int(1)
}
> Box::swap('a', 'b')
< Box::swap = NULL
string(1) "b"
string(1) "a"
> sort([3,2,1])
< sort = true
array(3) {
  [0]=>
  int(1)
  [1]=>
  int(2)
  [2]=>
  int(3)
}
> preg_match('/(\\d+)/', 'abc 42', NULL)
< preg_match = 1
array(2) {
  [0]=>
  string(2) "42"
  [1]=>
  string(2) "42"
}
-- dispatcher writes through $args
int(101)
int(101)
int(7)
