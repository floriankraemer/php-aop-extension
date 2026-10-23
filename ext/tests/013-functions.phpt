--TEST--
Plain, namespaced and internal functions and methods
--EXTENSIONS--
aop
--FILE--
<?php
namespace App\Util {
    function greet($who) { return "hello $who"; }
}
namespace {
require __DIR__.'/000-helper.inc';

function shout($s) { return strtoupper($s); }

\AOP\Engine::register(
    fn (array $jp) => in_array($jp['name'], ['App\Util\greet', 'shout', 'str_repeat', 'format', '__construct', 'strrev'], true)
        && ($jp['class'] === null || in_array($jp['class'], ['DateTime', 'DateTimeImmutable'], true)) ? 1 : 0,
    function (array $jp, ?object $self, array $args, \AOP\OriginalCall $original) {
        printf("%s %s internal=%s static=%s class=%s this=%s args=%s\n", $jp['kind'], $jp['name'],
            var_export($jp['internal'], true), var_export($jp['static'], true), var_export($jp['class'], true),
            $self ? get_class($self) : 'null', json_encode($args));
        if ($jp['name'] === 'str_repeat') {
            return $original([$args[0], $args[1] + 1]);
        }
        return $original($args);
    }
);

var_dump(\App\Util\greet('you'));
var_dump(shout('hey'));
var_dump(str_repeat('ab', 2));
var_dump(str_repeat(times: 1, string: 'x'));
$d = new DateTime('2024-01-02 03:04:05', new DateTimeZone('UTC'));
var_dump($d->format('Y-m-d'));
$im = new DateTimeImmutable('@0');
var_dump($im->format('Y'));
var_dump(strrev('abc'));         // compiled to a regular call: intercepted
var_dump(strlen('abc'));         // compiled to an opcode: never intercepted
var_dump(array_map('strrev', ['xy']));
}
?>
--EXPECT--
function-execution App\Util\greet internal=false static=false class=NULL this=null args=["you"]
string(9) "hello you"
function-execution shout internal=false static=false class=NULL this=null args=["hey"]
string(3) "HEY"
function-execution str_repeat internal=true static=false class=NULL this=null args=["ab",2]
string(6) "ababab"
function-execution str_repeat internal=true static=false class=NULL this=null args=["x",1]
string(2) "xx"
constructor-execution __construct internal=true static=false class='DateTime' this=DateTime args=["2024-01-02 03:04:05",{"timezone_type":3,"timezone":"UTC"}]
method-execution format internal=true static=false class='DateTime' this=DateTime args=["Y-m-d"]
string(10) "2024-01-02"
constructor-execution __construct internal=true static=false class='DateTimeImmutable' this=DateTimeImmutable args=["@0"]
method-execution format internal=true static=false class='DateTimeImmutable' this=DateTimeImmutable args=["Y"]
string(4) "1970"
function-execution strrev internal=true static=false class=NULL this=null args=["abc"]
string(3) "cba"
int(3)
function-execution strrev internal=true static=false class=NULL this=null args=["xy"]
array(1) {
  [0]=>
  string(2) "yx"
}
