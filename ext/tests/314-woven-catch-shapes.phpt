--TEST--
Woven catch blocks: multi-catch picks the catching class, non-capturing catch leaves no variable, nested try/finally with throwing advice
--EXTENSIONS--
aop
--FILE--
<?php
namespace App {
    use RuntimeException as RE;

    class Svc
    {
        // Use-imported alias and a namespaced class in one multi-catch: ::class resolves them at compile time.
        public function multi(\Throwable $t): string
        {
            try {
                throw $t;
            } catch (\LogicException | RE | MyError $e) {
                return 'multi '.get_class($e);
            }
        }

        public function nonCapturing(): array
        {
            $before = 1;
            try {
                throw new RE('x');
            } catch (RE) {
                return array_keys(get_defined_vars());
            }
        }

        public function nested(): void
        {
            try {
                try {
                    throw new RE('inner');
                } catch (RE $e) {
                    echo "  body not reached\n";
                } catch (\Throwable $e) {
                    echo "  sibling not reached\n";
                } finally {
                    echo "  finally 1\n";
                }
            } catch (\DomainException $e) {
                echo "  outer caught ", $e->getMessage(), "\n";
            } finally {
                echo "  finally 2\n";
            }
        }
    }
    class MyError extends \Exception {}
}
namespace {
    $seen = [];
    \AOP\Engine::register(
        function (array $jp) {
            return 'exception-handler' === $jp['kind'] ? \AOP\Engine::ADVISE : 0;
        },
        function (array $jp, ?object $self, array $args, \AOP\OriginalCall $orig) use (&$seen) {
            $seen[] = $jp['name'];
            if ('RuntimeException' === $jp['name'] && 'inner' === $args[0]->getMessage()) {
                throw new DomainException('from advice');
            }
        }
    );
    $svc = new App\Svc();
    foreach ([new RuntimeException('r'), new App\MyError('m'), new InvalidArgumentException('i')] as $t) {
        echo $svc->multi($t), "\n";
    }
    echo implode(',', $seen), "\n";
    var_dump($svc->nonCapturing());
    $svc->nested();
    echo implode(',', $seen), "\n";
}
?>
--EXPECT--
multi RuntimeException
multi App\MyError
multi InvalidArgumentException
RuntimeException,App\MyError,LogicException
array(1) {
  [0]=>
  string(6) "before"
}
  finally 1
  outer caught from advice
  finally 2
RuntimeException,App\MyError,LogicException,RuntimeException,RuntimeException,DomainException
