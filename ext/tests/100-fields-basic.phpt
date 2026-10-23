--TEST--
Fields: field-get/field-set descriptors, OriginalCall semantics, substituted and skipped accesses
--EXTENSIONS--
aop
--INI--
aop.interception=hook
--FILE--
<?php
require __DIR__.'/100-helper.inc';
class Account
{
    public $balance = 10;
    public $note;
    public function deposit($n) { $this->balance = $this->balance + $n; return $this->balance; }
}

$log = [];
\AOP\Engine::register(
    function (array $jp) use (&$log) {
        if (str_ends_with($jp['kind'], '-execution')) {
            return 0;
        }
        $log[] = $jp;
        return str_starts_with($jp['kind'], 'field-') ? 1 : 0;
    },
    function (array $jp, ?object $self, array $args, \AOP\OriginalCall $original) {
        static $first = true;
        if ($first) {
            $first = false;
            echo "descriptor: ";
            $copy = $jp;
            $copy['caller']['file'] = basename($copy['caller']['file']);
            $copy['caller']['object'] = g_fmt($copy['caller']['object']);
            echo json_encode($copy), "\n";
        }
        echo $jp['kind'], ' ', $jp['name'], ' this=', g_fmt($self), ' args=', g_fmt($args),
            array_key_exists('value', $jp) ? ' value='.g_fmt($jp['value']) : '', "\n";
        if ('note' === $jp['name'] && 'field-get' === $jp['kind']) {
            return 'substituted';
        }
        if ('note' === $jp['name']) {
            return 'ignored'; // not proceeding: nothing is written
        }
        return $original($args);
    }
);

$a = new Account();
var_dump($a->deposit(5));
var_dump($a->balance);
$a->note = 'written?';
var_dump($a->note);
var_dump((fn () => $this->note)->call($a)); // closures are code too: still a field-get
// the result of an advised assignment expression is the assigned value
var_dump($a->balance = 99);
echo "matcher saw:\n";
foreach ($log as $jp) {
    echo '  ', $jp['kind'], ' ', $jp['class'], ' ', $jp['scope'] ?? 'null', ' ', $jp['name'], ' caller=', json_encode($jp['caller']),
        ' keys=', implode(',', array_keys($jp)), "\n";
}
?>
--EXPECT--
descriptor: {"kind":"field-get","class":"Account","scope":"Account","name":"balance","static":false,"internal":false,"caller":{"class":"Account","scope":"Account","function":"deposit","file":"100-fields-basic.php","line":7,"object":"Account"}}
field-get balance this=Account args=[]
field-set balance this=Account args=[15] value=15
field-get balance this=Account args=[]
int(15)
field-get balance this=Account args=[]
int(15)
field-set note this=Account args=['written?'] value='written?'
field-get note this=Account args=[]
string(11) "substituted"
field-get note this=Account args=[]
string(11) "substituted"
field-set balance this=Account args=[99] value=99
int(99)
matcher saw:
  field-get Account null * caller=null keys=kind,class,scope,name,static,internal,caller
  initialization Account Account __construct caller=null keys=kind,class,scope,name,static,internal,caller
  field-get Account Account balance caller=null keys=kind,class,scope,name,static,internal,caller
  field-set Account Account balance caller=null keys=kind,class,scope,name,static,internal,caller
  field-set Account Account note caller=null keys=kind,class,scope,name,static,internal,caller
  field-get Account Account note caller=null keys=kind,class,scope,name,static,internal,caller
