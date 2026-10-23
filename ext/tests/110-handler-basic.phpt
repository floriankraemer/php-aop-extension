--TEST--
exception-handler: descriptor, inheritance, multi-catch, finally, non-matching clauses, nested and rethrown exceptions
--EXTENSIONS--
aop
--FILE--
<?php
require __DIR__.'/100-helper.inc';
class AppException extends RuntimeException {}
class Service
{
    public function run(callable $f)
    {
        try {
            $f();
        } catch (InvalidArgumentException $e) {
            return 'invalid';
        } catch (LogicException | RuntimeException $e) {
            return 'caught '.get_class($e).': '.$e->getMessage();
        }
        return 'nothing thrown';
    }
    public static function onlyFinally()
    {
        try {
            throw new AppException('through finally');
        } finally {
            echo "  finally ran\n";
        }
    }
}

$seen = [];
\AOP\Engine::register(
    function (array $jp) use (&$seen) {
        if ('exception-handler' !== $jp['kind']) {
            return 0;
        }
        $seen[] = $jp;
        return 1;
    },
    function (array $jp, ?object $self, array $args, \AOP\OriginalCall $original) {
        echo '  > catch(', $jp['name'], ') of ', get_class($jp['exception']), ' in ', $jp['class'] ?? '-', '/', $jp['scope'] ?? '-',
            ' static=', var_export($jp['static'], true), ' this=', g_fmt($self), ' args=', g_fmt($args),
            ' from ', g_caller($jp['caller']), ' same=', var_export($args[0] === $jp['exception'], true),
            ' original=', g_fmt($original([])), "\n";
        return 'ignored';
    }
);

$s = new Service();
echo $s->run(fn () => throw new AppException('app')), "\n";
echo $s->run(fn () => throw new DomainException('domain')), "\n";
echo $s->run(fn () => throw new InvalidArgumentException('arg')), "\n";
echo $s->run(fn () => null), "\n";
try {
    Service::onlyFinally();
} catch (Exception $e) {
    echo 'outer: ', $e->getMessage(), "\n";
}
function nested()
{
    try {
        try {
            throw new AppException('inner');
        } catch (AppException $e) {
            throw new LogicException('rethrown', 0, $e);
        }
    } catch (LogicException $e) {
        return $e->getMessage().' <- '.$e->getPrevious()->getMessage();
    }
}
echo nested(), "\n";
try {
    throw new TypeError('no handler matches here');
} catch (ValueError $e) {
    echo "wrong\n";
} catch (Error $e) {
    echo 'top-level: ', $e->getMessage(), "\n";
}
echo "matcher was asked once per (code, class, catch class):\n";
foreach ($seen as $jp) {
    echo '  ', $jp['name'], ' in ', $jp['class'] ?? '-', ' caller=', g_caller($jp['caller']), ' exception key: ', var_export(array_key_exists('exception', $jp), true), "\n";
}
?>
--EXPECT--
  > catch(RuntimeException) of AppException in Service/Service static=false this=Service args=[AppException] from Service::run@12 same=true original=NULL
caught AppException: app
  > catch(LogicException) of DomainException in Service/Service static=false this=Service args=[DomainException] from Service::run@12 same=true original=NULL
caught DomainException: domain
  > catch(InvalidArgumentException) of InvalidArgumentException in Service/Service static=false this=Service args=[InvalidArgumentException] from Service::run@10 same=true original=NULL
invalid
nothing thrown
  finally ran
  > catch(Exception) of AppException in -/- static=false this=NULL args=[AppException] from {main}@52 same=true original=NULL
outer: through finally
  > catch(AppException) of AppException in -/- static=false this=NULL args=[AppException] from nested@60 same=true original=NULL
  > catch(LogicException) of LogicException in -/- static=false this=NULL args=[LogicException] from nested@63 same=true original=NULL
rethrown <- inner
  > catch(Error) of TypeError in -/- static=false this=NULL args=[TypeError] from {main}@72 same=true original=NULL
top-level: no handler matches here
matcher was asked once per (code, class, catch class):
  RuntimeException in Service caller=null exception key: false
  LogicException in Service caller=null exception key: false
  InvalidArgumentException in Service caller=null exception key: false
  Exception in - caller=null exception key: false
  AppException in - caller=null exception key: false
  LogicException in - caller=null exception key: false
  Error in - caller=null exception key: false
