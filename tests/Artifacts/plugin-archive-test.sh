#!/usr/bin/env bash
# Verifies the plugin distribution archive: the Go agent, binaries, tests, CI
# and development files stay out, the runtime files of the plugin are in.
#
#   tests/Artifacts/plugin-archive-test.sh [--worktree]   build and check both archives
#   tests/Artifacts/plugin-archive-test.sh ZIP...         check given archives
#
# Built archives are `git archive` of HEAD (what GitHub and Packagist ship,
# honouring export-ignore in .gitattributes) and `composer archive`
# (honouring archive.exclude in composer.json). --worktree archives the
# current working tree instead of HEAD, for checks before a commit.
set -euo pipefail

REPOSITORY_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)
readonly REPOSITORY_DIR
readonly PACKAGE=muckiware/mucki-safe-agent-plugin
# Anchored at the archive root after stripping a common top-level folder.
readonly FORBIDDEN='^(agent|tests|\.github|docs|examples|vendor|var|node_modules)(/|$)|\.go$|(^|/)go\.(mod|sum)$|(^|/)mucki-agent(-linux-[a-z0-9]+)?$|^(phpunit\.xml\.dist|phpstan\.neon|\.gitignore|\.gitattributes|\.phpunit\.result\.cache)$'
readonly REQUIRED=(
    composer.json
    LICENSE
    src/MuckiSafeAgentPlugin.php
    src/Resources/config/services.xml
    src/Resources/config/routes.xml
    resources/schema/protocol/v1/heartbeat-request.schema.json
)
WORK=""
FAILED=0

fail() {
    echo "not ok - $*" >&2
    FAILED=1
}

# entries ZIP prints the archive's file names relative to its root folder.
entries() {
    local names top
    names=$(unzip -Z1 "$1" | grep -v '/$')
    top=$(head -n 1 <<<"$names" | cut -d/ -f1)
    if [ -n "$top" ] && ! grep -qv "^$top/" <<<"$names"; then
        cut -d/ -f2- <<<"$names"
    else
        echo "$names"
    fi
}

check_archive() {
    local zip=$1 names offending path extracted
    names=$(entries "$zip")
    offending=$(grep -E "$FORBIDDEN" <<<"$names" | head -10 || true)
    [ -z "$offending" ] || fail "$(basename "$zip") contains development or agent files: $(tr '\n' ' ' <<<"$offending")"
    for path in "${REQUIRED[@]}"; do
        grep -qx "$path" <<<"$names" || fail "$(basename "$zip") misses $path"
    done
    extracted=$(mktemp -d "$WORK/extract.XXXX")
    unzip -q "$zip" -d "$extracted"
    if find "$extracted" -type f -exec sh -c 'head -c 4 "$1" | grep -q "ELF"' _ {} \; -print | grep -q .; then
        fail "$(basename "$zip") contains an ELF binary"
    fi
    if ! find "$extracted" -name composer.json -maxdepth 2 -exec jq -e --arg name "$PACKAGE" '.name == $name' {} \; | grep -q true; then
        fail "$(basename "$zip"): composer.json is not $PACKAGE"
    fi
    [ "$FAILED" -ne 0 ] || echo "ok - $(basename "$zip"): $(wc -l <<<"$names" | tr -d ' ') files, no agent, Go, test or CI files"
}

git_tree() {
    if [ "${1:-}" != --worktree ]; then
        echo HEAD
        return
    fi
    local index=$WORK/index
    GIT_INDEX_FILE=$index git -C "$REPOSITORY_DIR" read-tree HEAD
    GIT_INDEX_FILE=$index git -C "$REPOSITORY_DIR" add -A
    GIT_INDEX_FILE=$index git -C "$REPOSITORY_DIR" write-tree
}

build_archives() {
    local tree
    tree=$(git_tree "${1:-}")
    git -C "$REPOSITORY_DIR" archive --format=zip --prefix=MuckiSafeAgentPlugin/ -o "$WORK/git-archive.zip" "$tree"
    (cd "$REPOSITORY_DIR" && composer archive --quiet --format=zip --dir="$WORK" --file=composer-archive)
}

main() {
    WORK=$(mktemp -d)
    trap 'rm -rf "$WORK"' EXIT
    local archives=("$@")
    if [ $# -eq 0 ] || [ "$1" = --worktree ]; then
        build_archives "${1:-}"
        archives=("$WORK/git-archive.zip" "$WORK/composer-archive.zip")
    fi
    local zip
    for zip in "${archives[@]}"; do
        check_archive "$zip"
    done
    exit "$FAILED"
}

main "$@"
