--TEST--
Never intercepted: return by reference, __call trampolines (but __call itself is), include and eval code
--EXTENSIONS--
aop
--FILE--
<?php
class Magic
{
    public function __call($name, $args) { return "__call($name)"; }
    public static function __callStatic($name, $args) { return "__callStatic($name)"; }
}
class Refs
{
    public array $data = ['a' => 1];
    public function &get($k) { return $this->data[$k]; }
}
file_put_contents(__DIR__.'/021-included.inc', '<?php return included_fn();');
function included_fn() { return 'included_fn'; }

$names = [];
\AOP\Engine::register(
    function (array $jp) use (&$names) {
        if (!str_ends_with($jp['kind'], '-execution')) {
            return 0; // field probes and initialization (stream G) are not what this test is about
        }
        if (!$jp['internal']) {
            $names[] = ($jp['class'] ?? '').'::'.$jp['name'];
        }
        return $jp['internal'] ? 0 : 1;
    },
    fn ($jp, $self, $args, $original) => $original($args)
);
$m = new Magic();
var_dump($m->undefined(1));
var_dump(Magic::undefinedStatic());
$r = new Refs();
$ref = &$r->get('a');
$ref = 2;
var_dump($r->data['a']);
var_dump(include __DIR__.'/021-included.inc');
var_dump(eval('return included_fn();'));
\AOP\Engine::unregister();
var_dump($names);
?>
--CLEAN--
<?php
@unlink(__DIR__.'/021-included.inc');
?>
--EXPECT--
string(17) "__call(undefined)"
string(29) "__callStatic(undefinedStatic)"
int(2)
string(11) "included_fn"
string(11) "included_fn"
array(3) {
  [0]=>
  string(13) "Magic::__call"
  [1]=>
  string(19) "Magic::__callStatic"
  [2]=>
  string(13) "::included_fn"
}
