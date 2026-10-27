#!/usr/bin/env bash
# Checks the upstream hooks listed in ext/UPSTREAM_TOUCHPOINTS.md.
#  1. Every row with status "added" whose marker column names CUSTOM(ext) has that marker in the file.
#  2. composer.json carries the two ext autoload entries.
#  3. No upstream file (outside ext/) carries a CUSTOM(ext) marker without being listed.
# Exit code 0 = all good. Used by CI, merge-upstream.sh and ext/tests/Unit/TouchpointsTest.php.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
list="$root/ext/UPSTREAM_TOUCHPOINTS.md"
fail=0
listed=()

while IFS='|' read -r _ file _hook marker _phase status _; do
    file="$(echo "$file" | sed -E 's/^ *`?([^` ]+)`?.*$/\1/')"
    status="$(echo "$status" | xargs)"
    [[ -z "$file" || "$file" == "File" || "$file" == ---* ]] && continue
    [[ "$file" == *"/"* || "$file" == *.* ]] || continue
    listed+=("$file")
    [[ "$status" == "added" ]] || continue
    if [[ "$marker" == *"CUSTOM(ext)"* ]]; then
        if [[ ! -f "$root/$file" ]]; then
            echo "MISSING FILE: $file"; fail=1
        elif ! grep -q 'CUSTOM(ext)' "$root/$file"; then
            echo "MISSING MARKER: $file has no CUSTOM(ext) marker"; fail=1
        else
            echo "ok: $file"
        fi
    fi
done < <(sed -n '/^| File | Hook/,/^$/p' "$list")

if grep -q '"FireflyIII\\\\Ext\\\\": "ext/src/"' "$root/composer.json" \
    && grep -q '"FireflyIII\\\\Ext\\\\Tests\\\\": "ext/tests/"' "$root/composer.json"; then
    echo "ok: composer.json autoload entries"
else
    echo "MISSING: composer.json autoload entries for FireflyIII\\Ext"; fail=1
fi

while IFS= read -r found; do
    rel="${found#"$root"/}"
    known=0
    for item in "${listed[@]}"; do [[ "$item" == "$rel" ]] && known=1; done
    if [[ $known -eq 0 ]]; then
        echo "UNLISTED: $rel has a CUSTOM(ext) marker but is not in ext/UPSTREAM_TOUCHPOINTS.md"; fail=1
    fi
done < <(cd "$root" && grep -rl 'CUSTOM(ext)' \
    --exclude-dir=ext --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=.git \
    --exclude-dir=storage --exclude-dir=build --exclude=CLAUDE.md . | sed "s#^\./#$root/#" | sort)

exit $fail
