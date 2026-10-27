#!/usr/bin/env bash
# Merge an upstream Firefly III release tag into the ext branch.
#
#   ext/scripts/merge-upstream.sh <tag> [--branch <branch>] [--no-tests]
#
# 1. fetch upstream tags and check the tag exists (release tags only: vX.Y.Z)
# 2. git merge --no-ff <tag> into the ext branch (or --branch)
# 3. on conflicts: list them, flag any file that is NOT a listed touchpoint, and stop.
#    Resolve by taking upstream's version and re-applying our one-line hook, then re-run
#    with the same arguments after "git commit".
# 4. check every CUSTOM(ext) marker (ext/scripts/check-touchpoints.sh)
# 5. run the ext tests (unless --no-tests)
# Pushing and tagging (ext-v<version>-ext.N) are left to the operator.
set -euo pipefail

usage() { echo "usage: $0 <tag> [--branch <branch>] [--no-tests]" >&2; exit 2; }
[[ $# -ge 1 ]] || usage
tag="$1"; shift
branch="ext"
run_tests=1
while [[ $# -gt 0 ]]; do
    case "$1" in
        --branch) branch="${2:?}"; shift 2 ;;
        --no-tests) run_tests=0; shift ;;
        *) usage ;;
    esac
done

root="$(git rev-parse --show-toplevel)"
cd "$root"

if [[ ! "$tag" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo "Refusing '$tag': only upstream release tags (vX.Y.Z) are merged into ext." >&2
    exit 1
fi
if [[ -n "$(git status --porcelain --untracked-files=no)" ]]; then
    echo "Working tree is not clean; commit or stash first." >&2
    exit 1
fi

if ! git remote get-url upstream > /dev/null 2>&1; then
    git remote add upstream https://github.com/firefly-iii/firefly-iii.git
fi
git fetch upstream --tags --no-recurse-submodules
git rev-parse -q --verify "refs/tags/$tag^{commit}" > /dev/null || { echo "Tag $tag not found upstream." >&2; exit 1; }

# Touchpoint files, from the first column of the table in UPSTREAM_TOUCHPOINTS.md.
mapfile -t touchpoints < <(sed -n '/^| File | Hook/,/^$/p' ext/UPSTREAM_TOUCHPOINTS.md \
    | awk -F'|' 'NR>2 {print $2}' | sed -E 's/^ *`?([^` ]+)`?.*$/\1/' | grep -E '[./]')

git checkout "$branch"
if ! git merge --no-ff "$tag" -m "Merge upstream $tag"; then
    echo
    echo "Merge of $tag has conflicts:"
    unexpected=0
    while IFS= read -r file; do
        known="UNEXPECTED (not a touchpoint)"
        for item in "${touchpoints[@]}"; do [[ "$item" == "$file" ]] && known="touchpoint"; done
        [[ "$file" == ext/* ]] && known="ext/ file (upstream should never touch ext/)"
        [[ "$known" == "touchpoint" ]] || unexpected=1
        echo "  $file: $known"
    done < <(git diff --name-only --diff-filter=U)
    echo
    echo "Resolve each file by taking upstream's version and re-applying the one-line CUSTOM(ext) hook:"
    echo "  git checkout --theirs <file> && <re-add the hook> && git add <file>"
    echo "Never keep our older copy of upstream code. Then: git commit, and re-run this script."
    [[ $unexpected -eq 0 ]] || echo "WARNING: conflicts outside the touchpoint list: stop and investigate." >&2
    exit 1
fi

bash ext/scripts/check-touchpoints.sh

if [[ $run_tests -eq 1 ]]; then
    composer install --no-interaction --no-scripts
    vendor/bin/phpunit -c ext/phpunit.xml
    node --test ext/tests/js/
fi

version="${tag#v}"
echo
echo "Merged $tag into $branch. Next: build the image, then"
echo "  git push origin $branch && git tag ext-v$version-ext.1 && git push origin ext-v$version-ext.1"
