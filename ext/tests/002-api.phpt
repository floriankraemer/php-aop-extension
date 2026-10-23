--TEST--
AOP\Engine and AOP\OriginalCall API surface
--EXTENSIONS--
aop
--FILE--
<?php
var_dump(\AOP\Engine::ADVISE, \AOP\Engine::TRACK, \AOP\Engine::CALLER_SENSITIVE);
$e = new ReflectionClass(\AOP\Engine::class);
$o = new ReflectionClass(\AOP\OriginalCall::class);
var_dump($e->isFinal(), $e->isInternal(), $o->isFinal(), $o->isInternal());

var_dump(\AOP\Engine::isRegistered());
\AOP\Engine::register(fn () => 0, fn () => null);
var_dump(\AOP\Engine::isRegistered());
\AOP\Engine::reset();
var_dump(\AOP\Engine::isRegistered());
\AOP\Engine::unregister();
var_dump(\AOP\Engine::isRegistered());
\AOP\Engine::unregister();

var_dump(\AOP\Engine::fieldInterceptionAvailable());
interface I {}
class Impl implements I {}
\AOP\Engine::setIntroductions([['typePattern' => 'Foo*', 'interface' => 'I', 'defaultImpl' => 'Impl']]);
\AOP\Engine::setIntroductions([]);

foreach ([
    fn () => new \AOP\Engine(),
    fn () => new \AOP\OriginalCall(),
    fn () => $o->newInstanceWithoutConstructor(),
    fn () => $o->newInstance(),
    fn () => unserialize('O:16:"AOP\OriginalCall":0:{}'),
    fn () => \AOP\Engine::register('no_such_function', fn () => null),
] as $f) {
    try {
        $f();
        echo "no error\n";
    } catch (\Throwable $t) {
        echo get_class($t), ': ', $t->getMessage(), "\n";
    }
}
var_dump(\AOP\Engine::isRegistered());
?>
--EXPECT--
int(1)
int(2)
int(4)
bool(true)
bool(true)
bool(true)
bool(true)
bool(false)
bool(true)
bool(true)
bool(false)
bool(true)
Error: Call to private AOP\Engine::__construct() from global scope
Error: Cannot directly construct AOP\OriginalCall
ReflectionException: Class AOP\OriginalCall is an internal class marked as final that cannot be instantiated without invoking its constructor
Error: Cannot directly construct AOP\OriginalCall
Exception: Unserialization of 'AOP\OriginalCall' is not allowed
TypeError: AOP\Engine::register(): Argument #1 ($matcher) must be a valid callback, function "no_such_function" not found or invalid function name
bool(false)
