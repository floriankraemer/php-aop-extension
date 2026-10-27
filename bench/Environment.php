<?php

declare(strict_types=1);

namespace AopBench;

use function AopBench\Workload\add;

/**
 * Fails the benchmark subject when the process is not in the configuration the driver (bench/run.sh) asked for,
 * so a silently disabled JIT or a missing extension cannot skew a published number.
 */
trait Environment
{
    public function assertEnvironment(): void
    {
        $expectedJit = getenv('AOP_BENCH_JIT_ON');
        $expectedState = getenv('AOP_BENCH_STATE');
        if (false === $expectedJit || false === $expectedState) {
            throw new \RuntimeException('Run the benchmarks through bench/run.sh (AOP_BENCH_JIT_ON and AOP_BENCH_STATE are unset).');
        }

        $status = function_exists('opcache_get_status') ? opcache_get_status(false) : false;
        $jit = is_array($status) ? ($status['jit'] ?? null) : null;
        $jitOn = is_array($jit) && true === ($jit['on'] ?? false);
        if ($jitOn !== ('1' === $expectedJit)) {
            throw new \RuntimeException(sprintf('JIT is %s but this cell expects it %s.', $jitOn ? 'on' : 'off', '1' === $expectedJit ? 'on' : 'off'));
        }

        if (extension_loaded('aop') !== ('none' !== $expectedState)) {
            throw new \RuntimeException(sprintf('aop is %s but state "%s" expects the opposite.', extension_loaded('aop') ? 'loaded' : 'not loaded', $expectedState));
        }

        if ('pass' === $expectedState) {
            $before = Dispatch::$advised;
            $sum = add(1, 2);
            if (Dispatch::$advised === $before || 3 !== $sum) {
                throw new \RuntimeException('State "pass" expects advice to run, but the dispatcher was not called.');
            }
        }
    }
}
