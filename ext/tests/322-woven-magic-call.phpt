--TEST--
Woven __call and __callStatic are intercepted for the magic call, with the method name and arguments
--EXTENSIONS--
aop
--FILE--
<?php
class Magic
{
    public function __call(string $name, array $args): string { return "call {$name} ".json_encode($args); }
    public static function __callStatic(string $name, array $args): string { return "static {$name} ".json_encode($args); }
    public function real(): string { return 'real'; }
}

$mode = 'proceed';
\AOP\Engine::register(
    static fn (array $jp): int => (!$jp['internal'] && str_ends_with($jp['kind'], '-execution')) ? \AOP\Engine::ADVISE : 0,
    static function (array $jp, ?object $self, array $args, \AOP\OriginalCall $original) use (&$mode): mixed {
        echo "> {$jp['class']}::{$jp['name']} ", json_encode($args), " [{$mode}]\n";
        return 'proceed' === $mode ? '<'.$original($args).'>' : 'advice';
    }
);

$m = new Magic();
var_dump($m->undefined(1, 'two'), Magic::staticUndefined(3), $m->real(), $m->undefined());
var_dump(call_user_func([$m, 'viaCallable'], 'x'), call_user_func('Magic::viaStringCallable'));
$mode = 'skip';
var_dump($m->undefined(1), Magic::staticUndefined());
?>
--EXPECT--
> Magic::__call ["undefined",[1,"two"]] [proceed]
> Magic::__callStatic ["staticUndefined",[3]] [proceed]
> Magic::real [] [proceed]
> Magic::__call ["undefined",[]] [proceed]
string(26) "<call undefined [1,"two"]>"
string(28) "<static staticUndefined [3]>"
string(6) "<real>"
string(19) "<call undefined []>"
> Magic::__call ["viaCallable",["x"]] [proceed]
> Magic::__callStatic ["viaStringCallable",[]] [proceed]
string(24) "<call viaCallable ["x"]>"
string(29) "<static viaStringCallable []>"
> Magic::__call ["undefined",[1]] [skip]
> Magic::__callStatic ["staticUndefined",[]] [skip]
string(6) "advice"
string(6) "advice"
