--TEST--
Trait methods report the using class as scope; TRACK dispatches like ADVISE; anonymous classes
--EXTENSIONS--
aop
--FILE--
<?php
trait Greets { public function greet() { return 'hi from '.self::class; } }
class UsesTrait { use Greets; }

\AOP\Engine::register(
    fn (array $jp) => match ($jp['name']) { 'greet' => \AOP\Engine::TRACK, 'anon' => \AOP\Engine::ADVISE | \AOP\Engine::TRACK, default => 0 },
    function ($jp, $self, $args, $original) {
        echo $jp['kind'], ' ', $jp['class'] === get_class($self) ? 'runtime-class' : 'other', ' scope=',
            str_contains($jp['scope'], '@anonymous') ? 'anonymous' : $jp['scope'], "\n";
        return $original($args);
    }
);
var_dump((new UsesTrait())->greet());
$anon = new class { public function anon() { return 'anon'; } };
var_dump($anon->anon());
?>
--EXPECT--
method-execution runtime-class scope=UsesTrait
string(17) "hi from UsesTrait"
method-execution runtime-class scope=anonymous
string(4) "anon"
