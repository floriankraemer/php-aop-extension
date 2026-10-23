--TEST--
CALLER_SENSITIVE: asked again per caller function, both answers cached
--EXTENSIONS--
aop
--FILE--
<?php
class Target { public function hit() { return 'hit'; } }
class Allowed { public function go(Target $t) { return $t->hit(); } public function again(Target $t) { return $t->hit(); } }
class Denied { public function go(Target $t) { return $t->hit(); } }
function from_function(Target $t) { return $t->hit(); }

$asked = [];
\AOP\Engine::register(
    function (array $jp) use (&$asked) {
        if ($jp['name'] !== 'hit') {
            return 0;
        }
        $asked[] = $jp['caller'];
        if ($jp['caller'] === null) {
            return \AOP\Engine::CALLER_SENSITIVE;
        }
        // the second answer's CALLER_SENSITIVE bit is ignored
        return ($jp['caller']['class'] === 'Allowed' || $jp['caller']['function'] === null ? \AOP\Engine::ADVISE : 0)
            | \AOP\Engine::CALLER_SENSITIVE;
    },
    fn ($jp, $self, $args, $original) => 'advised:'.$original($args).'@'.($jp['caller']['class'] ?? '').'::'.($jp['caller']['function'] ?? 'main').':'.$jp['caller']['line']
);

$t = new Target();
$a = new Allowed();
$d = new Denied();
for ($i = 0; $i < 2; ++$i) {
    echo $a->go($t), "\n";
    echo $a->again($t), "\n";
    echo $d->go($t), "\n";
    echo from_function($t), "\n";
    echo $t->hit(), "\n";
    echo call_user_func([$t, 'hit']), "\n";   // compiled to INIT_USER_CALL: the caller is main
    echo array_map([$t, 'hit'], [0])[0], "\n"; // internal caller: caller => null, no second question
}
foreach ($asked as $c) {
    echo $c === null ? 'null' : json_encode([$c['class'], $c['scope'], $c['function'], basename($c['file']), array_key_exists('line', $c), array_key_exists('object', $c)]), "\n";
}
?>
--EXPECT--
advised:hit@Allowed::go:3
advised:hit@Allowed::again:3
hit
hit
advised:hit@::main:32
advised:hit@::main:33
hit
advised:hit@Allowed::go:3
advised:hit@Allowed::again:3
hit
hit
advised:hit@::main:32
advised:hit@::main:33
hit
null
["Allowed","Allowed","go","014-caller-sensitive.php",false,false]
["Allowed","Allowed","again","014-caller-sensitive.php",false,false]
["Denied","Denied","go","014-caller-sensitive.php",false,false]
[null,null,"from_function","014-caller-sensitive.php",false,false]
[null,null,null,"014-caller-sensitive.php",false,false]
