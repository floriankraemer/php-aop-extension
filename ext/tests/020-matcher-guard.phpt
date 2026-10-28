--TEST--
Nothing is intercepted while the matcher runs; matcher results are converted to int
--EXTENSIONS--
aop
--INI--
opcache.jit=disable
; opcache would inline these trivial functions (optimizer pass 16), see 025-opcache-inlining.phpt
opcache.optimization_level=0x7FFE3FFF
--FILE--
<?php
function helper($x) { return "helper($x)"; }
function target() { return 'target'; }

\AOP\Engine::register(
    function (array $jp) {
        echo 'match ', $jp['name'], ' -> ', helper($jp['name']), ' ', str_repeat('-', 2), "\n";
        return match ($jp['name']) { 'target' => '1', 'helper' => true, default => null };
    },
    fn ($jp, $self, $args, $original) => 'advised '.$original($args)
);
echo target(), "\n";
echo helper(1), "\n";
\AOP\Engine::unregister();
?>
--EXPECT--
match target -> helper(target) --
advised target
match helper -> helper(helper) --
advised helper(1)
