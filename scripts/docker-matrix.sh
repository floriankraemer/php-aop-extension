#!/usr/bin/env bash
# Host-side driver for the Docker matrix builds. Used by `make dist`,
# `make test-all` and by the release workflow.
#
#   scripts/docker-matrix.sh dist   build + phpt + package every cell into dist/
#   scripts/docker-matrix.sh test   build + phpt + phpunit for Debian cli/zts cells
#
# Environment (all optional):
#   VERSION       tarball version (default: git describe --tags --always, "v" stripped)
#   PHP_VERSIONS  space separated, default "8.3 8.4 8.5"
#   TS_MODES      "nts zts"        (nts -> php:X-cli-*, zts -> php:X-zts-*)
#   LIBCS         "glibc musl"     (glibc -> -bookworm, musl -> -alpine); test mode forces glibc
#   PLATFORMS     comma separated docker platforms, default: the host's.
#                 Foreign platforms need QEMU binfmt (make binfmt).
#   DEBIAN_SUITE  Debian image suffix, default "bookworm"
#   DIST_DIR      output dir, default <repo>/dist
#   DOCKER_ARGS   extra arguments for `docker run` (e.g. "--network host")
#   DRY_RUN=1     print the docker commands instead of running them
#   KEEP_IMAGES=1 keep pulled images (default: remove each image after its cell
#                 when PRUNE_IMAGES=1; useful on small disks)
set -euo pipefail

mode="${1:-}"
case "$mode" in dist | test) ;; *)
    echo "usage: $0 dist|test" >&2
    exit 2
    ;; esac

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_VERSIONS="${PHP_VERSIONS:-8.3 8.4 8.5}"
TS_MODES="${TS_MODES:-nts zts}"
LIBCS="${LIBCS:-glibc musl}"
DEBIAN_SUITE="${DEBIAN_SUITE:-bookworm}"
DIST_DIR="${DIST_DIR:-$root/dist}"
DRY_RUN="${DRY_RUN:-0}"
PRUNE_IMAGES="${PRUNE_IMAGES:-0}"

if [[ -z "${VERSION:-}" ]]; then
    VERSION="$(git -C "$root" describe --tags --always 2>/dev/null || echo dev)"
fi
VERSION="${VERSION#v}"

if [[ -z "${PLATFORMS:-}" ]]; then
    case "$(uname -m)" in
        x86_64 | amd64) PLATFORMS=linux/amd64 ;;
        aarch64 | arm64) PLATFORMS=linux/arm64 ;;
        *) PLATFORMS="linux/$(uname -m)" ;;
    esac
fi
[[ "$mode" == test ]] && LIBCS=glibc

run() {
    if [[ "$DRY_RUN" == 1 ]]; then
        printf '+'
        printf ' %q' "$@"
        printf '\n'
    else
        "$@"
    fi
}

mkdir -p "$DIST_DIR"
failed=()
count=0

IFS=',' read -r -a platforms <<<"$PLATFORMS"
for platform in "${platforms[@]}"; do
    for php in $PHP_VERSIONS; do
        for ts in $TS_MODES; do
            for libc in $LIBCS; do
                flavour=cli
                [[ "$ts" == zts ]] && flavour=zts
                if [[ "$libc" == musl ]]; then suffix=alpine; else suffix="$DEBIAN_SUITE"; fi
                image="php:${php}-${flavour}-${suffix}"
                cell="php${php}-${ts}-${libc}-${platform#linux/}"
                echo
                echo "##### ${mode}: ${cell} (${image})"
                count=$((count + 1))

                args=(docker run --rm --platform "$platform"
                    -v "$root:/src:ro"
                    -e "VERSION=$VERSION" -e "LIBC=$libc" -e "EXPECT_TS=$ts"
                    -e SRC=/src -e OUT=/out)
                if [[ "$mode" == dist ]]; then
                    args+=(-v "$DIST_DIR:/out" -e "HOST_UID=$(id -u)" -e "HOST_GID=$(id -g)")
                else
                    args+=(-v aop-composer-cache:/root/.composer/cache -e COMPOSER_HOME=/root/.composer)
                fi
                # shellcheck disable=SC2206  # word splitting of DOCKER_ARGS is intended
                args+=(${DOCKER_ARGS:-})
                args+=("$image" sh /src/scripts/container-build.sh "$mode")

                if ! run "${args[@]}"; then
                    failed+=("$cell")
                fi
                if [[ "$PRUNE_IMAGES" == 1 ]]; then
                    run docker image rm -f "$image" >/dev/null 2>&1 || true
                fi
            done
        done
    done
done

if [[ "$mode" == dist && "$DRY_RUN" != 1 ]]; then
    shopt -s nullglob
    files=("$DIST_DIR"/aop-"$VERSION"-*.tar.gz)
    if ((${#files[@]})); then
        (
            cd "$DIST_DIR"
            names=()
            for f in "${files[@]}"; do names+=("$(basename "$f")"); done
            if command -v sha256sum >/dev/null 2>&1; then
                sha256sum "${names[@]}" >SHA256SUMS
            else
                shasum -a 256 "${names[@]}" >SHA256SUMS
            fi
        )
        echo
        echo "dist/SHA256SUMS:"
        cat "$DIST_DIR/SHA256SUMS"
    fi
fi

echo
if ((${#failed[@]})); then
    echo "FAILED cells (${#failed[@]}/$count): ${failed[*]}" >&2
    exit 1
fi
echo "all $count cell(s) passed"
