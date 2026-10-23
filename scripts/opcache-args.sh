#!/bin/sh
# Prints the run-tests arguments that enable opcache for the CLI, loading it as a zend_extension
# when the PHP binary does not have it built in (PHP 8.3/8.4 ship it as a shared module, 8.5 builds it in).
# Usage: scripts/opcache-args.sh [php-binary]
set -eu
php=${1:-php}
args="-d opcache.enable_cli=1"
if ! "$php" -n -r 'exit(extension_loaded("Zend OPcache") ? 0 : 1);'; then
    dir=$("$php" -r 'echo ini_get("extension_dir");')
    if [ ! -f "$dir/opcache.so" ]; then
        echo "opcache.so not found in $dir" >&2
        exit 1
    fi
    args="-d zend_extension=$dir/opcache.so $args"
fi
echo "$args"
