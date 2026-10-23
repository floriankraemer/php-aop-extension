#!/usr/bin/env bash
# Checks scripts/semver-check.sh against valid and invalid tags.
set -uo pipefail
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
check="$here/semver-check.sh"
fail=0

# tag|expected version|expected prerelease
good=(
    'v1.2.3|1.2.3|false'
    '1.2.3|1.2.3|false'
    '0.0.0|0.0.0|false'
    'v10.20.30|10.20.30|false'
    '1.2.3-rc.1+build.5|1.2.3-rc.1+build.5|true'
    'v1.0.0-alpha|1.0.0-alpha|true'
    'v1.0.0-alpha.1|1.0.0-alpha.1|true'
    'v1.0.0-0.3.7|1.0.0-0.3.7|true'
    'v1.0.0-x.7.z.92|1.0.0-x.7.z.92|true'
    'v1.0.0-x-y-z.--|1.0.0-x-y-z.--|true'
    '1.0.0+20130313144700|1.0.0+20130313144700|false'
    '1.0.0-beta+exp.sha.5114f85|1.0.0-beta+exp.sha.5114f85|true'
    '1.0.0+21AF26D3--117B344092BD|1.0.0+21AF26D3--117B344092BD|false'
    '1.0.0-rc.1+001|1.0.0-rc.1+001|true'
    'refs/tags/v2.0.0|2.0.0|false'
)
bad=(
    ''
    'v1.2'
    '1'
    'v1.2.3.4'
    'v01.2.3'
    'v1.02.3'
    'v1.2.03'
    '1.2.3-'
    '1.2.3+'
    '1.2.3-+build'
    '1.2.3-01'
    '1.2.3-rc..1'
    '1.2.3-rc.'
    '1.2.3-.rc'
    '1.2.3+b..c'
    '1.2.3-rc_1'
    'vv1.2.3'
    'V1.2.3'
    'release-1.2.3'
    'v1.2.3 '
    ' v1.2.3'
    'v1.2.x'
    'latest'
)

for entry in "${good[@]}"; do
    IFS='|' read -r tag want_version want_pre <<<"$entry"
    out="$("$check" "$tag" 2>&1)"; rc=$?
    expected=$'version='"$want_version"$'\nis_prerelease='"$want_pre"
    if [[ $rc -eq 0 && "$out" == "$expected" ]]; then
        echo "ok   good: '$tag'"
    else
        echo "FAIL good: '$tag' (rc=$rc) got: $out"; fail=1
    fi
done

for tag in "${bad[@]}"; do
    if out="$("$check" "$tag" 2>&1)"; then
        echo "FAIL bad: '$tag' was accepted: $out"; fail=1
    else
        echo "ok   bad:  '$tag'"
    fi
done

if [[ $fail -ne 0 ]]; then echo "semver-check tests FAILED"; exit 1; fi
echo "semver-check tests passed"
