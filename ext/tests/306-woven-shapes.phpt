--TEST--
Woven: value, void, never and constructor functions, static and variadic methods, named and by-reference arguments, fake closures
--EXTENSIONS--
aop
--INI--
opcache.jit=disable
aop.interception=woven
--FILE--
<?php
namespace App;

class Point
{
    public function __construct(public readonly int $x, private string $label = 'p')
    {
        echo "constructing {$this->x}\n";
    }
}

class Svc
{
    public static function sum(int ...$n): int { return array_sum($n); }
    public function push(array &$list, $v): int { $list[] = $v; return count($list); }
    public function named(int $a, int $b = 5, int ...$rest): array { return [$a, $b, $rest]; }
    public function hello(): void { echo "hello body\n"; }
    public function &byRef(): int { static $n = 1; return $n; }
}

function fail(): never { throw new \RuntimeException('original'); }
function quiet(): void { echo "quiet body\n"; }
function typed(): int { return 1; }

$mode = 'proceed';
\AOP\Engine::register(
    static fn (array $jp): int => ($jp['internal'] || 'exception-handler' === $jp['kind']) ? 0 : \AOP\Engine::ADVISE,
    static function (array $jp, ?object $self, array $args, \AOP\OriginalCall $original) use (&$mode): mixed {
        echo '> ', ($jp['class'] ?? ''), '::', $jp['name'], ' ', json_encode($args), " [{$mode}]\n";
        return 'proceed' === $mode ? $original($args) : null;
    }
);

echo "-- constructor with promoted readonly property\n";
$p = new Point(4);
var_dump($p->x);

echo "-- static, variadic, named\n";
var_dump(Svc::sum(1, 2, 3));
$svc = new Svc();
var_dump($svc->named(1, b: 7), $svc->named(1, 2, 3, 4), $svc->named(a: 3));

echo "-- by reference\n";
$list = [1];
var_dump($svc->push($list, 2), $list);

echo "-- void and never\n";
var_dump(quiet());
$svc->hello();
try { fail(); } catch (\RuntimeException $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }

echo "-- fake closures\n";
$f = $svc->named(...);
var_dump($f(9)[0]);
$g = Svc::sum(...);
var_dump($g(5, 5));

echo "-- by-reference returns are not intercepted\n";
var_dump($svc->byRef());

echo "-- advice that does not proceed\n";
$mode = 'skip';
$q = new Point(7);
var_dump(isset($q->x));
var_dump(quiet());
try { fail(); } catch (\TypeError $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
try { var_dump(typed()); } catch (\TypeError $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
?>
--EXPECT--
-- constructor with promoted readonly property
> App\Point::__construct [4] [proceed]
constructing 4
int(4)
-- static, variadic, named
> App\Svc::sum [1,2,3] [proceed]
int(6)
> App\Svc::named [1,7] [proceed]
> App\Svc::named [1,2,3,4] [proceed]
> App\Svc::named [3] [proceed]
array(3) {
  [0]=>
  int(1)
  [1]=>
  int(7)
  [2]=>
  array(0) {
  }
}
array(3) {
  [0]=>
  int(1)
  [1]=>
  int(2)
  [2]=>
  array(2) {
    [0]=>
    int(3)
    [1]=>
    int(4)
  }
}
array(3) {
  [0]=>
  int(3)
  [1]=>
  int(5)
  [2]=>
  array(0) {
  }
}
-- by reference
> App\Svc::push [[1],2] [proceed]
int(2)
array(2) {
  [0]=>
  int(1)
  [1]=>
  int(2)
}
-- void and never
> ::App\quiet [] [proceed]
quiet body
NULL
> App\Svc::hello [] [proceed]
hello body
> ::App\fail [] [proceed]
RuntimeException: original
-- fake closures
> App\Svc::named [9] [proceed]
int(9)
> App\Svc::sum [5,5] [proceed]
int(10)
-- by-reference returns are not intercepted
int(1)
-- advice that does not proceed
> App\Point::__construct [7] [skip]
bool(false)
> ::App\quiet [] [skip]
NULL
> ::App\fail [] [skip]
TypeError: App\fail(): never-returning function must not implicitly return
> ::App\typed [] [skip]
TypeError: App\typed(): Return value must be of type int, null returned
