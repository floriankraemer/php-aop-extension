--TEST--
Woven catch blocks: nested non-capturing catches and catches inside closures and static closures
--EXTENSIONS--
aop
--INI--
opcache.jit=disable
--FILE--
<?php
class Svc
{
    public function nested(): array
    {
        $log = [];
        try {
            try {
                try {
                    throw new RuntimeException('deep');
                } catch (LogicException) {
                    $log[] = 'wrong';
                } catch (RuntimeException) {
                    $log[] = 'inner';
                    try {
                        throw new DomainException('again');
                    } catch (DomainException | InvalidArgumentException) {
                        $log[] = 'inner-inner';
                    }
                    throw new LengthException('up');
                }
            } catch (OutOfRangeException) {
                $log[] = 'wrong';
            }
        } catch (LogicException) {
            $log[] = 'outer';
            $log[] = array_keys(get_defined_vars());
        }
        return $log;
    }

    public function closures(): array
    {
        $plain = function (Throwable $t): string {
            try {
                throw $t;
            } catch (RuntimeException | LogicException $e) {
                return 'closure '.get_class($e);
            } catch (Throwable) {
                return 'closure other';
            }
        };
        $static = static function (): string {
            try {
                throw new Error('e');
            } catch (Error) {
                return 'static closure';
            }
        };
        $bound = fn (Throwable $t) => (function () use ($t) {
            try {
                throw $t;
            } catch (Exception $e) {
                return 'nested '.$e->getMessage();
            }
        })();
        return [$plain(new LogicException()), $plain(new TypeError()), $static(), $bound(new Exception('m'))];
    }
}

$seen = [];
\AOP\Engine::register(
    static fn (array $jp): int => 'exception-handler' === $jp['kind'] ? \AOP\Engine::ADVISE : 0,
    static function (array $jp, ?object $self, array $args, \AOP\OriginalCall $original) use (&$seen): mixed {
        $seen[] = $jp['name'].' in '.($jp['scope'] ?? '-');
        return $original($args);
    }
);

$s = new Svc();
echo json_encode($s->nested()), "\n";
echo json_encode($s->closures()), "\n";
echo implode("\n", $seen), "\n";
?>
--EXPECT--
["inner","inner-inner","outer",["log"]]
["closure LogicException","closure other","static closure","nested m"]
RuntimeException in Svc
DomainException in Svc
LogicException in Svc
LogicException in Svc
Throwable in Svc
Error in Svc
Exception in Svc
