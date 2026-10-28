--TEST--
Functions in the AOP and AOP\ namespaces are not woven, AOPX and similar namespaces are; braced and unbraced namespaces
--EXTENSIONS--
aop
--INI--
opcache.jit=disable
--FILE--
<?php
namespace AOP\Foo {
    function inside_aop(): string { return 'inside'; }
}

namespace AOPX {
    function lookalike(): string { return 'lookalike'; }
}

namespace aop {
    function lower_case_root(): string { return 'lower'; }
}

namespace App {
    function app(): string { return 'app'; }
}

namespace AOP {
    function root_ns(): string { return 'root'; }
}

namespace After {
    function after_braced(): string { return 'after'; }
}

namespace {
    $seen = [];
    \AOP\Engine::register(
        static fn (array $jp): int => (!$jp['internal'] && str_ends_with($jp['kind'], '-execution')) ? \AOP\Engine::ADVISE : 0,
        static function (array $jp, ?object $self, array $args, \AOP\OriginalCall $original) use (&$seen): mixed {
            $seen[] = $jp['name'];
            return $original($args);
        }
    );
    $r = [\AOP\Foo\inside_aop(), \AOPX\lookalike(), \aop\lower_case_root(), \App\app(), \AOP\root_ns(), \After\after_braced()];
    echo json_encode($r), "\n", implode(' ', $seen), "\n";
}
?>
--EXPECT--
["inside","lookalike","lower","app","root","after"]
AOPX\lookalike App\app After\after_braced
