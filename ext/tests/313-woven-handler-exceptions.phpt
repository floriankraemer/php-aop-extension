--TEST--
exception-handler (woven): an exception from the dispatcher replaces the caught one (sibling catches skipped, finally runs); matcher exceptions; AOP\ code and generators
--EXTENSIONS--
aop
--SKIPIF--
<?php
if (ini_get('aop.interception') !== 'woven') die('skip needs the default aop.interception=woven');
?>
--FILE--
<?php
namespace AOP\Runtime {
    function internalCatch() {
        try {
            throw new \RuntimeException('runtime-internal');
        } catch (\RuntimeException $e) {
            return 'AOP\\ catch: '.$e->getMessage();
        }
    }
}
namespace {
    require __DIR__.'/100-helper.inc';
    class Tracked extends RuntimeException
    {
        public function __destruct() { echo "  released ", $this->getMessage(), "\n"; }
    }
    function work()
    {
        try {
            try {
                throw new Tracked('original');
            } catch (Tracked $e) {
                echo "not reached\n";
            } catch (LogicException $e) {
                echo "a sibling clause must not catch the replacement\n";
            } finally {
                echo "  inner finally\n";
            }
        } catch (LogicException $e) {
            return 'outer caught '.$e->getMessage().', previous: '.var_export($e->getPrevious(), true);
        }
    }
    function gen()
    {
        try {
            yield 1;
            throw new Tracked('in generator');
        } catch (Tracked $e) {
            yield 'generator caught '.$e->getMessage();
        }
    }

    $mode = 'throw';
    \AOP\Engine::register(
        function (array $jp) use (&$mode) {
            if ('exception-handler' !== $jp['kind']) {
                return 0;
            }
            if ('matcher' === $mode && 'Tracked' === $jp['name']) {
                throw new UnexpectedValueException('matcher broke');
            }
            return 1;
        },
        function (array $jp, ?object $self, array $args, \AOP\OriginalCall $original) use (&$mode) {
            echo '  > catch(', $jp['name'], ') ', $jp['exception']->getMessage(), "\n";
            if ('throw' === $mode && $jp['exception'] instanceof Tracked) {
                throw new LogicException('replacement');
            }
            return null;
        }
    );

    echo work(), "\n";
    echo \AOP\Runtime\internalCatch(), "\n";
    $mode = 'pass';
    foreach (gen() as $v) {
        echo $v, "\n";
    }
    echo "matcher exception:\n";
    $mode = 'matcher';
    \AOP\Engine::reset();
    try {
        throw new Tracked('still caught');
    } catch (Tracked $e) {
        echo 'caught ', $e->getMessage(), "\n";
    }
    unset($e);
    echo "error handler turning the warning into an exception:\n";
    set_error_handler(function ($no, $msg) { throw new ErrorException('from handler'); });
    \AOP\Engine::reset();
    try {
        try {
            throw new Tracked('replaced by the error handler');
        } catch (Tracked $e) {
            echo "not reached\n";
        }
    } catch (ErrorException $e) {
        echo 'outer: ', $e->getMessage(), "\n";
    }
    restore_error_handler();
    echo "end\n";
}
?>
--EXPECTF--
  > catch(Tracked) original
  inner finally
  > catch(LogicException) replacement
  released original
outer caught replacement, previous: NULL
AOP\ catch: runtime-internal
1
  > catch(Tracked) in generator
generator caught in generator
  released in generator
matcher exception:

Warning: AOP\Engine: the matcher threw UnexpectedValueException (matcher broke) for exception-handler catch(Tracked); the join point is not intercepted in %s on line %d
caught still caught
  released still caught
error handler turning the warning into an exception:
  > catch(ErrorException) from handler
outer: from handler
end
  released replaced by the error handler
