--TEST--
initialization: ctor-less classes from new ($this = the new object, no-op OriginalCall, before arguments are evaluated); dispatcher exceptions abort new
--EXTENSIONS--
aop
--INI--
aop.interception=hook
--FILE--
<?php
require __DIR__.'/100-helper.inc';
class Plain { public $v = 1; public function __destruct() { echo "  destruct Plain\n"; } }
class WithCtor { public function __construct() {} }
class Inherits extends WithCtor {}
abstract class Abs {}
class Factory
{
    public static function make() { return new static(); }
}
class Made extends Factory {}
function arg($x) { echo "  argument evaluated\n"; return $x; }

$fail = false;
\AOP\Engine::register(
    function (array $jp) {
        if ('initialization' !== $jp['kind']) {
            return 0;
        }
        echo '  ? ', json_encode($jp), "\n";
        return 1;
    },
    function (array $jp, ?object $self, array $args, \AOP\OriginalCall $original) use (&$fail) {
        echo '  > ', $jp['kind'], ' ', $jp['class'], ' this=', g_fmt($self), ' args=', g_fmt($args), ' from ', g_caller($jp['caller']),
            ' original=', g_fmt($original([])), "\n";
        if ($fail) {
            throw new RuntimeException('no '.$jp['class']);
        }
        return 'ignored';
    }
);

$p = new Plain();
var_dump($p->v);
$p2 = new Plain(arg(1), 2);
new WithCtor();
new Inherits();
var_dump(get_class(Made::make()));
$s = new stdClass();
try {
    new Abs();
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
$fail = true;
try {
    $x = new Plain();
} catch (RuntimeException $e) {
    echo $e->getMessage(), ', $x is ', isset($x) ? 'set' : 'unset', "\n";
}
echo "end\n";
?>
--EXPECT--
  ? {"kind":"initialization","class":"Plain","scope":"Plain","name":"__construct","static":false,"internal":false,"caller":null}
  > initialization Plain this=Plain args=[] from {main}@33 original=NULL
int(1)
  > initialization Plain this=Plain args=[] from {main}@35 original=NULL
  argument evaluated
  ? {"kind":"initialization","class":"Made","scope":"Made","name":"__construct","static":false,"internal":false,"caller":null}
  > initialization Made this=Made args=[] from Made::make@9 original=NULL
string(4) "Made"
  ? {"kind":"initialization","class":"stdClass","scope":"stdClass","name":"__construct","static":false,"internal":true,"caller":null}
  > initialization stdClass this=stdClass args=[] from {main}@39 original=NULL
Cannot instantiate abstract class Abs
  > initialization Plain this=Plain args=[] from {main}@47 original=NULL
no Plain, $x is unset
end
  destruct Plain
  destruct Plain
