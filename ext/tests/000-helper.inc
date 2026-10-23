<?php
/* Shared by the phpt tests: a plain-PHP matcher/dispatcher pair, no PHP package needed. */

function jp_name(array $jp): string
{
    return (null !== $jp['class'] ? $jp['class'].'::' : '').$jp['name'];
}

function fmt(mixed $v): string
{
    if (is_object($v)) {
        return get_class($v);
    }
    if (is_array($v)) {
        return json_encode($v);
    }
    return var_export($v, true);
}

/** Logging "around" dispatcher that proceeds once with the arguments it received. */
function logging_dispatcher(): \Closure
{
    return static function (array $jp, ?object $self, array $args, \AOP\OriginalCall $original): mixed {
        echo '> ', jp_name($jp), '(', implode(', ', array_map('fmt', $args)), ")\n";
        $result = $original($args);
        echo '< ', jp_name($jp), ' = ', fmt($result), "\n";
        return $result;
    };
}

/** Advises the join points whose "Class::name" (runtime class) or function name is listed. */
function advise(array $names, ?callable $dispatcher = null): void
{
    \AOP\Engine::register(
        static fn (array $jp): int => in_array(jp_name($jp), $names, true) ? \AOP\Engine::ADVISE : 0,
        $dispatcher ?? logging_dispatcher()
    );
}
