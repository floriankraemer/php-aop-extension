<?php
/* Shared by the stream G phpt tests (1xx): field, handler and initialization join points. */

function g_fmt(mixed $v): string
{
    if (is_object($v)) {
        return get_class($v);
    }
    if (is_array($v)) {
        return '['.implode(', ', array_map('g_fmt', $v)).']';
    }
    return var_export($v, true);
}

function g_caller(?array $c): string
{
    if (null === $c) {
        return 'null';
    }
    return ($c['class'] ?? '').(null !== $c['class'] ? '::' : '').($c['function'] ?? '{main}')
        .(isset($c['line']) ? '@'.$c['line'] : '');
}

/**
 * Registers a matcher advising the listed kinds (all non-execution kinds by default; field probes
 * included) and a logging dispatcher that proceeds with the arguments it got.
 */
function g_register(array $kinds = ['field-get', 'field-set', 'initialization', 'exception-handler'], ?callable $dispatch = null, bool $logMatcher = false): void
{
    \AOP\Engine::register(
        static function (array $jp) use ($kinds, $logMatcher): int {
            if (!in_array($jp['kind'], $kinds, true)) {
                return 0;
            }
            if ($logMatcher) {
                echo '  ? ', $jp['kind'], ' ', $jp['class'] ?? '-', ' ', $jp['name'], "\n";
            }
            return \AOP\Engine::ADVISE;
        },
        $dispatch ?? static function (array $jp, ?object $self, array $args, \AOP\OriginalCall $original): mixed {
            echo '  > ', $jp['kind'], ' ', $jp['class'] ?? '-', '::', $jp['name'], '(', implode(', ', array_map('g_fmt', $args)), ')',
                ' from ', g_caller($jp['caller']), "\n";
            $r = $original($args);
            if ('field-get' === $jp['kind']) {
                echo '  < ', g_fmt($r), "\n";
            }
            return $r;
        }
    );
}
