--TEST--
opcache: woven classes are cached with their file; fields, handlers and initialization work on cached op arrays
--EXTENSIONS--
aop
--SKIPIF--
<?php
if (!function_exists('opcache_get_status') || !@opcache_get_status(false)) die('skip opcache not active');
?>
--INI--
opcache.enable_cli=1
opcache.jit=off
--FILE--
<?php
require __DIR__.'/100-helper.inc';
require __DIR__.'/120-intro-lib.inc';
\AOP\Engine::setIntroductions([['typePattern' => 'App\Model\**', 'interface' => 'Lib\Greeter', 'defaultImpl' => 'Lib\DefaultGreeter']]);
require __DIR__.'/120-intro-classes.inc';
var_dump(opcache_is_script_cached(__DIR__.'/120-intro-classes.inc'));
$p = new App\Model\Post();
echo $p->greet('opcache'), "\n";
var_dump($p instanceof Lib\Greeter, (new Other\Outside()) instanceof Lib\Greeter);

class Box { public $v = 1; public function bump() { $this->v++; return $this->v; } }
g_register(['field-get', 'field-set', 'exception-handler', 'initialization']);
$b = new Box();
var_dump($b->bump());
try {
    throw new LogicException('x');
} catch (LogicException $e) {
    echo "caught\n";
}
?>
--EXPECTF--
bool(true)
hello opcache! (warm, call 1)
bool(true)
bool(false)
  > initialization Box::__construct() from {main}@13
  > field-get Box::v() from Box::bump@11
  < 1
  > field-set Box::v(2) from Box::bump@11
  > field-get Box::v() from Box::bump@11
  < 2
int(2)
  > exception-handler -::LogicException(LogicException) from {main}@17
caught
