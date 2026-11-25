#!/usr/bin/env bash
# Verifies an agent release directory built by build-release.sh:
#   - both binaries are static Linux ELF executables (file, readelf: no
#     INTERP, no dynamic section; ldd on the native architecture), built
#     with CGO_ENABLED=0 and -trimpath for their architecture;
#   - SHA256SUMS covers every file and matches;
#   - --version and `config validate` run on this runner (native binary);
#   - the directory contains nothing of the PHP plugin.
#
#   agent/packaging/tests/binary-artifacts-test.sh DIR VERSION
set -euo pipefail

[ $# -eq 2 ] || { echo "usage: $0 DIR VERSION" >&2; exit 2; }
DIR=$(cd -- "$1" && pwd)
VERSION=${2#v}
readonly DIR VERSION
REPOSITORY_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../../.." && pwd)
readonly REPOSITORY_DIR
PASSED=0

ok() {
    PASSED=$((PASSED + 1))
    echo "ok $PASSED - $*"
}

fail() {
    echo "not ok - $*" >&2
    exit 1
}

native_arch() {
    case $(uname -m) in
        x86_64) echo amd64 ;;
        aarch64|arm64) echo arm64 ;;
        *) echo unknown ;;
    esac
}

check_static() {
    local arch=$1 binary=$DIR/mucki-agent-linux-$1 machine
    [ -f "$binary" ] && [ -x "$binary" ] || fail "$binary missing or not executable"
    machine=$([ "$arch" = amd64 ] && echo x86-64 || echo aarch64)
    file -b "$binary" | grep -q "ELF 64-bit LSB executable.*$machine.*statically linked" ||
        fail "$arch: not a static ELF executable: $(file -b "$binary")"
    ! readelf -lW "$binary" | grep -q INTERP || fail "$arch: program header requests an interpreter"
    readelf -dW "$binary" | grep -q "There is no dynamic section" || fail "$arch: dynamic section: $(readelf -dW "$binary" | head -5)"
    ok "$arch binary is a static ELF executable without interpreter or dynamic section"
}

check_build_settings() {
    local arch=$1 binary=$DIR/mucki-agent-linux-$1 info setting
    info=$(go version -m "$binary") || fail "$arch: no Go build information"
    for setting in "-trimpath=true" "CGO_ENABLED=0" "GOOS=linux" "GOARCH=$arch"; do
        grep -qE "^[[:space:]]+build[[:space:]]+$setting\$" <<<"$info" || fail "$arch: build setting $setting missing"
    done
    ! grep -aqF "$REPOSITORY_DIR" "$binary" || fail "$arch: binary contains the build path $REPOSITORY_DIR"
    ok "$arch binary was built with CGO_ENABLED=0 and -trimpath"
}

check_checksums() {
    local file listed
    (cd "$DIR" && sha256sum --check --strict --quiet SHA256SUMS) || fail "SHA256SUMS does not match"
    while IFS= read -r file; do
        listed=${file#./}
        [ "$listed" = SHA256SUMS ] && continue
        grep -qE "^[0-9a-f]{64}  \*?${listed//./\\.}\$" "$DIR/SHA256SUMS" || fail "$listed is not listed in SHA256SUMS"
    done < <(cd "$DIR" && find . -type f | sort)
    ok "SHA256SUMS lists and matches every file"
}

check_separation() {
    local offending
    offending=$(cd "$DIR" && find . -type f \( -name '*.php' -o -name 'composer.json' -o -name '*.go' -o -name 'go.mod' \) | head -5)
    [ -z "$offending" ] || fail "agent release contains plugin or source files: $offending"
    for file in install.sh uninstall.sh systemd/mucki-agent.service config.example.yaml policy.example.yaml; do
        [ -f "$DIR/$file" ] || fail "$file missing in the agent release"
    done
    ok "agent release holds binaries, installer, unit and examples, nothing of the plugin"
}

check_runs_natively() {
    local arch binary output work
    arch=$(native_arch)
    binary=$DIR/mucki-agent-linux-$arch
    [ -f "$binary" ] || { echo "# skip: no binary for the runner architecture $(uname -m)"; return; }
    output=$(ldd "$binary" 2>&1 || true)
    grep -qE "not a dynamic executable|statically linked" <<<"$output" || fail "$arch: ldd reports dependencies: $output"
    output=$("$binary" --version)
    [[ $output == "mucki-agent $VERSION ("*") linux/$arch" ]] || fail "$arch: --version printed '$output'"
    work=$(mktemp -d)
    head -c 32 /dev/urandom | base64 | tr '+/' '-_' | tr -d '=\n' > "$work/secret"
    printf 'version: 1\n' > "$work/policy.yaml"
    printf 'server:\n  url: https://backup.example.org\n  keyId: 0123456789abcdef0123456789abcdef\n  secretFile: %s/secret\nagent:\n  policyFile: %s/policy.yaml\n' "$work" "$work" > "$work/config.yaml"
    chmod 0600 "$work/secret" "$work/config.yaml"
    output=$("$binary" config validate --config "$work/config.yaml" 2>&1) || fail "$arch: config validate failed: $output"
    rm -rf "$work"
    ok "$arch binary runs on this runner: ldd finds no dependencies, --version and config validate succeed"
}

for arch in amd64 arm64; do
    check_static "$arch"
    check_build_settings "$arch"
done
check_checksums
check_separation
check_runs_natively
echo "1..$PASSED"
