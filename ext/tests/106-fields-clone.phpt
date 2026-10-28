--TEST--
Fields: clones keep the wrapped handlers, accesses in __clone are covered, deferred OriginalCall keeps the object alive
--EXTENSIONS--
aop
--INI--
opcache.jit=disable
aop.interception=hook
--FILE--
<?php
require __DIR__.'/100-helper.inc';
class Doc
{
    public $title = 't';
    public $copies = 0;
    public function __clone() { $this->copies++; }
    public function __destruct() { echo "  destruct {$this->title}\n"; }
}
$later = [];
g_register(['field-get', 'field-set'], function (array $jp, ?object $self, array $args, \AOP\OriginalCall $original) use (&$later) {
    echo '  > ', $jp['kind'], ' ', $jp['name'], ' from ', g_caller($jp['caller']), "\n";
    if ('title' === $jp['name'] && 'field-set' === $jp['kind']) {
        $later[] = [$original, $args];
        return null; // deferred
    }
    return $original($args);
});
$d = new Doc();
$e = clone $d;
$f = clone $e;
var_dump($f->copies, $e->copies, $d->copies);
$f->title = 'deferred';
var_dump($f->title);
unset($d, $e, $f);
echo "running deferred set\n";
foreach ($later as [$original, $args]) {
    $original($args);
}
$later = [];
unset($original, $args);
echo "done\n";
?>
--EXPECT--
  > field-get copies from Doc::__clone@7
  > field-set copies from Doc::__clone@7
  > field-get copies from Doc::__clone@7
  > field-set copies from Doc::__clone@7
  > field-get copies from {main}@22
  > field-get copies from {main}@22
  > field-get copies from {main}@22
int(2)
int(1)
int(0)
  > field-set title from {main}@23
  > field-get title from {main}@24
string(1) "t"
  > field-get title from Doc::__destruct@8
  destruct t
  > field-get title from Doc::__destruct@8
  destruct t
running deferred set
  > field-get title from Doc::__destruct@8
  destruct deferred
done
