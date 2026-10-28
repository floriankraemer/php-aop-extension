--TEST--
Constructors: kind, $this, outermost with parent::__construct(), proceed zero times
--EXTENSIONS--
aop
--INI--
opcache.jit=disable
--FILE--
<?php
class ParentC
{
    public array $log = [];
    public function __construct(public string $name = 'p') { $this->log[] = 'ParentC'; }
}
class ChildC extends ParentC
{
    public function __construct(string $name) { $this->log[] = 'ChildC'; parent::__construct($name); }
}
class Inherits extends ParentC {}
class Factory
{
    public static function build() { return new ChildC('built'); }
}

$skip = false;
\AOP\Engine::register(
    fn (array $jp) => $jp['kind'] === 'constructor-execution' && !$jp['internal'] ? 1 : 0,
    function (array $jp, ?object $self, array $args, \AOP\OriginalCall $original) use (&$skip) {
        printf("%s %s::%s (scope %s) outermost=%s this=%s caller=%s args=%s\n", $jp['kind'], $jp['class'], $jp['name'],
            $jp['scope'], var_export($jp['outermost'], true), get_class($self),
            $jp['caller'] ? ($jp['caller']['class'] ?? '').'::'.($jp['caller']['function'] ?? 'main') : 'null', json_encode($args));
        if (!$skip) {
            $original($args);
        }
        return 'ignored';
    }
);

$c = new ChildC('c');
echo json_encode($c->log), ' ', $c->name, "\n";
$i = new Inherits();
echo json_encode($i->log), ' ', $i->name, "\n";
$f = Factory::build();
echo json_encode($f->log), "\n";
$skip = true;
$s = new ChildC('skipped');
var_dump($s->log, isset($s->name));
?>
--EXPECT--
constructor-execution ChildC::__construct (scope ChildC) outermost=true this=ChildC caller=::main args=["c"]
constructor-execution ChildC::__construct (scope ParentC) outermost=false this=ChildC caller=ChildC::__construct args=["c"]
["ChildC","ParentC"] c
constructor-execution Inherits::__construct (scope ParentC) outermost=true this=Inherits caller=::main args=[]
["ParentC"] p
constructor-execution ChildC::__construct (scope ChildC) outermost=true this=ChildC caller=Factory::build args=["built"]
constructor-execution ChildC::__construct (scope ParentC) outermost=false this=ChildC caller=ChildC::__construct args=["built"]
["ChildC","ParentC"]
constructor-execution ChildC::__construct (scope ChildC) outermost=true this=ChildC caller=::main args=["skipped"]
array(0) {
}
bool(false)
