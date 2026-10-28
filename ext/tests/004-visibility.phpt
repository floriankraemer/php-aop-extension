--TEST--
Public, protected, private, static and final methods, final classes and self-invocation
--EXTENSIONS--
aop
--INI--
opcache.jit=disable
--FILE--
<?php
require __DIR__.'/000-helper.inc';

class Service
{
    public function pub($x) { return 'pub:'.$this->prot($x); }
    protected function prot($x) { return 'prot:'.$this->priv($x); }
    private function priv($x) { return 'priv:'.$x; }
    public static function stat($x) { return 'stat:'.static::class.':'.$x; }
    final public function fin() { return 'fin'; }
    public function notAdvised() { return 'plain:'.$this->priv(1); }
}
final class Leaf
{
    public function run() { return 'leaf'; }
}
class Child extends Service {}

advise(['Service::pub', 'Service::prot', 'Service::priv', 'Service::stat', 'Service::fin', 'Leaf::run',
    'Child::stat', 'Child::pub', 'Child::prot', 'Child::priv']);

$s = new Service();
var_dump($s->pub(1));
var_dump(Service::stat(2));
var_dump($s->fin());
var_dump((new Leaf())->run());
var_dump($s->notAdvised());
echo "-- child\n";
var_dump(Child::stat(3));
var_dump((new Child())->pub(4));
?>
--EXPECT--
> Service::pub(1)
> Service::prot(1)
> Service::priv(1)
< Service::priv = 'priv:1'
< Service::prot = 'prot:priv:1'
< Service::pub = 'pub:prot:priv:1'
string(15) "pub:prot:priv:1"
> Service::stat(2)
< Service::stat = 'stat:Service:2'
string(14) "stat:Service:2"
> Service::fin()
< Service::fin = 'fin'
string(3) "fin"
> Leaf::run()
< Leaf::run = 'leaf'
string(4) "leaf"
> Service::priv(1)
< Service::priv = 'priv:1'
string(12) "plain:priv:1"
-- child
> Child::stat(3)
< Child::stat = 'stat:Child:3'
string(12) "stat:Child:3"
> Child::pub(4)
> Child::prot(4)
> Child::priv(4)
< Child::priv = 'priv:4'
< Child::prot = 'prot:priv:4'
< Child::pub = 'pub:prot:priv:4'
string(15) "pub:prot:priv:4"
