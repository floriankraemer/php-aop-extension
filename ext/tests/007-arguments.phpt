--TEST--
Raw arguments: variadics, named arguments, omitted defaults, extra arguments
--EXTENSIONS--
aop
--FILE--
<?php
require __DIR__.'/000-helper.inc';

function defaults($a, $b = 'B', $c = 'C') { return "$a$b$c"; }
function variadic($a, ...$rest) { return $a.'|'.json_encode($rest); }
function noargs() { return func_get_args(); }
class Typed
{
    public function coerce(int $i, string ...$s) { return [$i, $s]; }
}

$seen = [];
\AOP\Engine::register(
    fn (array $jp) => in_array($jp['name'], ['defaults', 'variadic', 'noargs', 'coerce'], true) ? 1 : 0,
    function (array $jp, ?object $self, array $args, \AOP\OriginalCall $original) {
        echo $jp['name'], ' ', json_encode($args), ' => ';
        $r = $original($args);
        echo json_encode($r), "\n";
        return $r;
    }
);

defaults(1);                       // omitted defaults are absent
defaults(1, 2);
defaults(1, 2, 3);
defaults(1, c: 3);                 // skipped optional parameter gets its default
defaults(c: 3, a: 1);
defaults(...['a' => 'x', 'b' => 'y']);
variadic(1);
variadic(1, 2, 3);
variadic(1, 2, x: 3, y: 4);        // extra named arguments for a variadic
variadic(...[1, 2, 'k' => 3]);
noargs(1, 2, 3);                   // extra positional arguments
noargs();
noargs(str_repeat('a', 3), [1, 2], new stdClass()); // refcounted extra arguments
variadic(str_repeat('x', 2), k: str_repeat('y', 2), l: [3]);
(new Typed())->coerce('5', 6, 7);  // raw (before coercion); the original coerces
call_user_func_array('variadic', ['a' => 1, 'z' => 2]);
?>
--EXPECT--
defaults [1] => "1BC"
defaults [1,2] => "12C"
defaults [1,2,3] => "123"
defaults [1,"B",3] => "1B3"
defaults [1,"B",3] => "1B3"
defaults ["x","y"] => "xyC"
variadic [1] => "1|[]"
variadic [1,2,3] => "1|[2,3]"
variadic {"0":1,"1":2,"x":3,"y":4} => "1|{\"0\":2,\"x\":3,\"y\":4}"
variadic {"0":1,"1":2,"k":3} => "1|{\"0\":2,\"k\":3}"
noargs [1,2,3] => [1,2,3]
noargs [] => []
noargs ["aaa",[1,2],{}] => ["aaa",[1,2],{}]
variadic {"0":"xx","k":"yy","l":[3]} => "xx|{\"k\":\"yy\",\"l\":[3]}"
coerce ["5",6,7] => [5,["6","7"]]
variadic {"0":1,"z":2} => "1|{\"z\":2}"
