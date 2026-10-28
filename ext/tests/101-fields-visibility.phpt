--TEST--
Fields: visibility is checked before the join point; private shadowing; OriginalCall keeps the accessing scope
--EXTENSIONS--
aop
--INI--
opcache.jit=disable
aop.interception=hook
--FILE--
<?php
require __DIR__.'/100-helper.inc';
class Base
{
    private $secret = 'base-secret';
    protected $prot = 'prot';
    public function baseSecret() { return $this->secret; }
}
class Derived extends Base
{
    public $secret = 'derived-secret'; // a different property than Base::$secret
    public function derivedProt() { return $this->prot; }
}
class Magic
{
    private $hidden = 'hidden';
    public function __get($n) { return "__get($n)"; }
}

$keep = [];
g_register(['field-get', 'field-set'], function (array $jp, ?object $self, array $args, \AOP\OriginalCall $original) use (&$keep) {
    echo '  > ', $jp['kind'], ' ', $jp['class'], ' scope=', $jp['scope'] ?? 'null', ' ', $jp['name'], ' from ', g_caller($jp['caller']), "\n";
    $keep[] = $original;
    return $original($args);
});

$d = new Derived();
var_dump($d->secret, $d->baseSecret(), $d->derivedProt());
foreach (['prot' => fn () => $d->prot, 'Base::secret from outside' => fn () => (new Base())->secret] as $what => $f) {
    try {
        $f();
    } catch (Error $e) {
        echo $what, ': ', $e->getMessage(), "\n";
    }
}
// inaccessible + __get: no field join point, __get runs as usual
var_dump((new Magic())->hidden);
// the OriginalCall keeps the scope of the access: still works later, from anywhere
echo "replay:\n";
foreach ($keep as $original) {
    var_dump($original([]));
}
?>
--EXPECT--
  > field-get Derived scope=Derived secret from {main}@28
  > field-get Derived scope=Base secret from Derived::baseSecret@7
  > field-get Derived scope=Base prot from Derived::derivedProt@12
string(14) "derived-secret"
string(11) "base-secret"
string(4) "prot"
prot: Cannot access protected property Derived::$prot
Base::secret from outside: Cannot access private property Base::$secret
string(13) "__get(hidden)"
replay:
string(14) "derived-secret"
string(11) "base-secret"
string(4) "prot"
