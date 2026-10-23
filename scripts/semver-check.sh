#!/usr/bin/env bash
# Validate a release tag against SemVer 2.0.0 (optional leading "v").
#
# Usage: scripts/semver-check.sh <tag>
# On success prints "version=<x.y.z[-pre][+build]>" and "is_prerelease=<true|false>"
# (one per line, ready to append to $GITHUB_OUTPUT) and exits 0.
# On failure prints an error to stderr and exits 1.
set -euo pipefail

tag="${1:-}"
tag="${tag#refs/tags/}"

num='(0|[1-9][0-9]*)'
ident='(0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*)'
build_ident='[0-9A-Za-z-]+'
re="^v?${num}\\.${num}\\.${num}(-${ident}(\\.${ident})*)?(\\+${build_ident}(\\.${build_ident})*)?\$"

if [[ ! "$tag" =~ $re ]]; then
    echo "error: '$tag' is not a valid semantic version tag (expected v?MAJOR.MINOR.PATCH[-prerelease][+build])" >&2
    exit 1
fi

version="${tag#v}"
core="${version%%+*}"
if [[ "$core" == *-* ]]; then
    prerelease=true
else
    prerelease=false
fi

echo "version=${version}"
echo "is_prerelease=${prerelease}"
