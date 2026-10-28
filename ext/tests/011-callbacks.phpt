--TEST--
Calls through call_user_func, array_map, Reflection, static:: and first-class callables
--EXTENSIONS--
aop
--INI--
opcache.jit=disable
--FILE--
<?php
require __DIR__.'/000-helper.inc';

class Base
{
    public static function create() { return static::make(); }
    public static function make() { return static::class; }
    public function twice($x) { return $x * 2; }
    private function secret() { return 'secret'; }
}
class Sub extends Base {}

advise(['Base::make', 'Sub::make', 'Base::twice', 'Base::secret']);

$b = new Base();
var_dump(call_user_func([$b, 'twice'], 1));
var_dump(call_user_func_array('Base::make', []));
var_dump(array_map([$b, 'twice'], [1, 2]));
var_dump((new ReflectionMethod(Base::class, 'twice'))->invoke($b, 5));
$m = new ReflectionMethod(Base::class, 'secret');
var_dump($m->invoke($b));
var_dump(Sub::create());
$f = $b->twice(...);
var_dump($f(21));
var_dump(array_map(Base::make(...), [1]));
$g = Closure::fromCallable([$b, 'twice']);
var_dump($g(4));
echo "-- real closures are never intercepted\n";
\AOP\Engine::register(fn ($jp) => $jp['internal'] ? 0 : \AOP\Engine::ADVISE, function () { echo "intercepted\n"; return null; });
$c = function ($x) { return $x + 1; };
var_dump($c(1));
var_dump((fn () => 'arrow')());
\AOP\Engine::unregister();
?>
--EXPECT--
> Base::twice(1)
< Base::twice = 2
int(2)
> Base::make()
< Base::make = 'Base'
string(4) "Base"
> Base::twice(1)
< Base::twice = 2
> Base::twice(2)
< Base::twice = 4
array(2) {
  [0]=>
  int(2)
  [1]=>
  int(4)
}
> Base::twice(5)
< Base::twice = 10
int(10)
> Base::secret()
< Base::secret = 'secret'
string(6) "secret"
> Sub::make()
< Sub::make = 'Sub'
string(3) "Sub"
> Base::twice(21)
< Base::twice = 42
int(42)
> Base::make(1)
< Base::make = 'Base'
array(1) {
  [0]=>
  string(4) "Base"
}
> Base::twice(4)
< Base::twice = 8
int(8)
-- real closures are never intercepted
int(2)
string(5) "arrow"
