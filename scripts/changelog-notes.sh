#!/usr/bin/env bash
# Print the CHANGELOG.md section for a version (Keep a Changelog format).
#
# Usage: scripts/changelog-notes.sh <version> [CHANGELOG.md]
# Looks for a "## [<version>]" heading (a leading "v" is ignored). Exits 1 when
# the section is missing or empty so callers can fall back to generated notes.
set -euo pipefail

version="${1:?usage: changelog-notes.sh <version> [file]}"
version="${version#v}"
file="${2:-CHANGELOG.md}"
[[ -f "$file" ]] || exit 1

notes="$(awk -v ver="$version" '
    /^## / {
        if (found) exit
        head = $0
        sub(/^## +\[?/, "", head)
        sub(/\]?([ ]+-.*)?$/, "", head)
        if (head == ver) { found = 1; next }
    }
    found { print }
' "$file")"

# Trim leading/trailing blank lines.
notes="$(printf '%s\n' "$notes" | sed -e '/./,$!d' | sed -e ':a' -e '/^\n*$/{$d;N;ba' -e '}')"
[[ -n "${notes//[[:space:]]/}" ]] || exit 1
printf '%s\n' "$notes"
