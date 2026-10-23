--TEST--
Fields: matcher guard, AOP\ code, reset()/unregister(), matcher and dispatcher exceptions, CALLER_SENSITIVE
--EXTENSIONS--
aop
--FILE--
<?php
namespace AOP\Runtime {
    class Reader
    {
        public $own = 'own';
        public function read(object $o) { return $o->name; }
        public function reflect(object $o) { return (new \ReflectionProperty($o, 'name'))->getValue($o); }
    }
}
namespace {
    require __DIR__.'/100-helper.inc';
    class Item
    {
        public $name = 'item';
        public $other = 'other';
        public function readName() { return $this->name; }
    }
    function viaFunction(Item $i) { return $i->name; }

    $probe = null;
    $mode = 'plain';
    \AOP\Engine::register(
        function (array $jp) use (&$probe, &$mode) {
            if (!str_starts_with($jp['kind'], 'field-')) {
                return 0;
            }
            echo '  ? ', $jp['kind'], ' ', $jp['class'], '::', $jp['name'], ' caller=', g_caller($jp['caller']), "\n";
            if ($probe) {
                $probe->name; // never intercepted: the matcher runs with interception suspended
            }
            if ('other' === $jp['name'] && 'throw' === $mode) {
                throw new RuntimeException('matcher broke');
            }
            return 'name' === $jp['name'] && 'cs' === $mode ? \AOP\Engine::ADVISE | \AOP\Engine::CALLER_SENSITIVE : 1;
        },
        function (array $jp, ?object $self, array $args, \AOP\OriginalCall $original) {
            echo '  > ', $jp['kind'], ' ', $jp['name'], ' from ', g_caller($jp['caller']), "\n";
            if ('other' === $jp['name']) {
                throw new LogicException('dispatcher broke on '.$jp['kind']);
            }
            return $original($args);
        }
    );

    $i = new Item();
    $probe = $i;
    $r = new \AOP\Runtime\Reader();
    echo "AOP\\ code:\n";
    var_dump($r->read($i), $r->reflect($i), $r->own);
    echo "dispatcher exceptions:\n";
    foreach ([fn () => $i->other, function () use ($i) { $i->other = 'changed'; }] as $f) {
        try {
            $f();
        } catch (LogicException $e) {
            echo '  ', $e->getMessage(), "\n";
        }
    }
    \AOP\Engine::unregister();
    var_dump($i->other); // unchanged: the set never proceeded
    echo "matcher exception:\n";
    $mode = 'throw';
    \AOP\Engine::register(fn (array $jp) => str_starts_with($jp['kind'], 'field-') && 'other' === $jp['name'] ? throw new RuntimeException('matcher broke') : 0, fn () => null);
    var_dump($i->other);
    \AOP\Engine::unregister();
    echo "unregistered: ";
    var_dump($i->name);
    echo "CALLER_SENSITIVE:\n";
    $mode = 'cs';
    $probe = null;
    \AOP\Engine::register(
        function (array $jp) {
            if (!str_starts_with($jp['kind'], 'field-')) {
                return 0;
            }
            echo '  ? ', $jp['name'], ' caller=', g_caller($jp['caller']), "\n";
            if (null === $jp['caller']) {
                return \AOP\Engine::CALLER_SENSITIVE;
            }
            return 'viaFunction' === $jp['caller']['function'] ? 1 : 0;
        },
        function (array $jp, ?object $self, array $args, \AOP\OriginalCall $original) {
            echo '  > ', $jp['name'], ' from ', g_caller($jp['caller']), "\n";
            return strtoupper($original($args));
        }
    );
    var_dump($i->readName(), viaFunction($i), viaFunction($i), $i->readName());
    echo "reset() asks again:\n";
    \AOP\Engine::reset();
    var_dump(viaFunction($i));
}
?>
--EXPECTF--
  ? field-get Item::* caller=null
AOP\ code:
string(4) "item"
string(4) "item"
string(3) "own"
dispatcher exceptions:
  ? field-get Item::other caller=null
  > field-get other from {closure}@51
  dispatcher broke on field-get
  ? field-set Item::other caller=null
  > field-set other from {closure}@51
  dispatcher broke on field-set
string(5) "other"
matcher exception:

Warning: AOP\Engine: the matcher threw RuntimeException (matcher broke) for field-get Item::$other; the join point is not intercepted in %s on line %d
string(5) "other"
unregistered: string(4) "item"
CALLER_SENSITIVE:
  ? name caller=null
  ? name caller=Item::readName
  ? name caller=viaFunction
  > name from viaFunction@18
  > name from viaFunction@18
string(4) "item"
string(4) "ITEM"
string(4) "ITEM"
string(4) "item"
reset() asks again:
  ? name caller=null
  ? name caller=viaFunction
  > name from viaFunction@18
string(4) "ITEM"
