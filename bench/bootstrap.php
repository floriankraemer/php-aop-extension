<?php

declare(strict_types=1);

use AopBench\Dispatch;

require __DIR__.'/../vendor/autoload.php';

// The workload is required here, not autoloaded: code the autoloader loads is not woven unless aop.weave_autoload=1,
// and the benchmark must run the same code in every state.
foreach (['functions', 'Calc', 'Order', 'Failing'] as $file) {
    require_once __DIR__."/Workload/$file.php";
}

// This file also runs in the phpbench host process, which never loads aop; only the benchmark subprocess does.
// States (set by bench/run.sh): none (no extension), loaded (nothing registered), miss, pass.
if (extension_loaded('aop')) {
    Dispatch::register(getenv('AOP_BENCH_STATE') ?: 'loaded');
}
