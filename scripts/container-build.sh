#!/bin/sh
# Runs INSIDE an official php:* container (Debian or Alpine). POSIX sh only.
#
#   container-build.sh dist   build, run phpt, package a tarball into $OUT
#   container-build.sh test   build, run phpt, composer install, phpunit unit + e2e
#
# Environment:
#   SRC        read-only repo mount            (default /src)
#   OUT        writable output dir, dist only  (default /out)
#   VERSION    version string for the tarball  (default dev)
#   LIBC       glibc|musl, for the tarball name (default: detected)
#   EXPECT_TS  nts|zts, abort if the image differs (optional)
#   HOST_UID / HOST_GID  chown the output to these (optional)
#
# The repo is copied to a temp dir first, so the host's ext/ is never touched.
set -eu

MODE="${1:-}"
SRC="${SRC:-/src}"
OUT="${OUT:-/out}"
VERSION="${VERSION:-dev}"

log() { printf '\n=== %s\n' "$*"; }

case "$MODE" in dist | test) ;; *)
    echo "usage: $0 dist|test" >&2
    exit 2
    ;; esac

# --- toolchain -------------------------------------------------------------
need_pkgs() {
    for tool in phpize autoconf make gcc; do
        command -v "$tool" >/dev/null 2>&1 || return 0
    done
    return 1
}
install_pkgs() { # install_pkgs <names...> ; best effort, apt or apk
    if command -v apk >/dev/null 2>&1; then
        apk add --no-cache "$@"
    else
        apt-get update -qq
        DEBIAN_FRONTEND=noninteractive apt-get install -y -qq --no-install-recommends "$@"
    fi
}
if need_pkgs; then
    log "installing build toolchain"
    # shellcheck disable=SC2086  # PHPIZE_DEPS is a word list by design
    install_pkgs ${PHPIZE_DEPS:-autoconf gcc g++ make pkg-config re2c}
fi

ZTS="$(php -r 'echo PHP_ZTS ? "zts" : "nts";')"
PHPMM="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
if [ -n "${EXPECT_TS:-}" ] && [ "$EXPECT_TS" != "$ZTS" ]; then
    echo "error: expected a $EXPECT_TS build of PHP but the image is $ZTS" >&2
    exit 1
fi
if [ -z "${LIBC:-}" ]; then
    if [ -e /etc/alpine-release ]; then LIBC=musl; else LIBC=glibc; fi
fi
case "$(uname -m)" in
    x86_64 | amd64) ARCH=x86_64 ;;
    aarch64 | arm64) ARCH=arm64 ;;
    *) ARCH="$(uname -m)" ;;
esac
log "PHP $(php -r 'echo PHP_VERSION;') $ZTS, $LIBC, $ARCH"

# --- private copy of the repo ---------------------------------------------
WORK="$(mktemp -d)"
cleanup() { rm -rf "$WORK"; }
trap cleanup EXIT
(cd "$SRC" && tar -cf - --exclude=./.git --exclude=./vendor --exclude=./dist --exclude=./node_modules .) |
    (cd "$WORK" && tar -xf -)
# Drop any build leftovers a developer may have in their tree.
(cd "$WORK/ext" && { [ ! -f Makefile ] || make distclean >/dev/null 2>&1 || true; } && phpize --clean >/dev/null 2>&1 || true)

# --- build -----------------------------------------------------------------
cd "$WORK/ext"
log "phpize / configure / make"
phpize
./configure --enable-aop
make -j"$(nproc 2>/dev/null || echo 2)"
[ -f modules/aop.so ] || {
    echo "error: modules/aop.so was not produced" >&2
    exit 1
}

# --- phpt ------------------------------------------------------------------
log "phpt"
export NO_INTERACTION=1 REPORT_EXIT_STATUS=1 TEST_PHP_ARGS="-q"
for pass in plain opcache; do
    if [ "$pass" = "opcache" ]; then
        TEST_PHP_ARGS="-q $("$SRC/scripts/opcache-args.sh" php)"
        export TEST_PHP_ARGS
    fi
    log "phpt ($pass)"
    if ! make test; then
        echo "phpt failures ($pass):" >&2
        find tests -name '*.diff' -exec sh -c 'echo "--- $1"; cat "$1"' _ {} \; >&2 || true
        exit 1
    fi
done

if [ "$MODE" = "dist" ]; then
    log "package"
    name="aop-${VERSION}-php${PHPMM}-${ZTS}-linux-${LIBC}-${ARCH}"
    stage="$WORK/stage/$name"
    mkdir -p "$stage" "$OUT"
    cp modules/aop.so "$stage/aop.so"
    cp "$WORK/LICENSE" "$stage/LICENSE"
    [ ! -f "$WORK/README.md" ] || cp "$WORK/README.md" "$stage/README.md"
    printf 'extension=aop\n' >"$stage/aop.ini"
    # Flat archive: the files sit at the top level.
    (cd "$stage" && tar -czf "$OUT/$name.tar.gz" -- *)
    if [ -n "${HOST_UID:-}" ]; then
        chown "$HOST_UID:${HOST_GID:-$HOST_UID}" "$OUT/$name.tar.gz"
    fi
    echo "wrote $OUT/$name.tar.gz"
    exit 0
fi

# --- test mode: phpunit ----------------------------------------------------
cd "$WORK"
if ! command -v composer >/dev/null 2>&1; then
    log "installing composer"
    if ! command -v git >/dev/null 2>&1 || ! command -v unzip >/dev/null 2>&1; then
        install_pkgs git unzip
    fi
    expected="$(php -r 'echo trim(file_get_contents("https://composer.github.io/installer.sig"));')"
    php -r 'copy("https://getcomposer.org/installer", "/tmp/composer-setup.php");'
    actual="$(php -r 'echo hash_file("sha384", "/tmp/composer-setup.php");')"
    [ "$expected" = "$actual" ] || {
        echo "error: composer installer checksum mismatch" >&2
        exit 1
    }
    php /tmp/composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer
    rm -f /tmp/composer-setup.php
fi
log "composer install"
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --no-progress --prefer-dist

SO="$WORK/ext/modules/aop.so"
log "phpunit unit"
php vendor/bin/phpunit --testsuite unit
if [ -d tests/E2E ]; then
    log "phpunit e2e (with extension)"
    php -d "extension=$SO" vendor/bin/phpunit --testsuite e2e
else
    echo "tests/E2E not present, skipping e2e"
fi
log "OK: PHP $PHPMM $ZTS $LIBC"
