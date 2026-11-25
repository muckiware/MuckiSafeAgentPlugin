#!/usr/bin/env bash
# Builds the Linux agent release directory: static binaries for amd64 and
# arm64, installer, uninstaller, systemd unit, example config and policy, and
# SHA256SUMS over all of them. The PHP plugin is released separately.
#
#   agent/packaging/build-release.sh VERSION OUTDIR
#
# VERSION is the semantic version without or with a leading "v".
set -euo pipefail

[ $# -eq 2 ] || { echo "usage: $0 VERSION OUTDIR" >&2; exit 2; }
version=${1#v}
out=$2
[[ $version =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$ ]] || { echo "invalid version: $1" >&2; exit 2; }

PACKAGING_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
AGENT_DIR=$(dirname -- "$PACKAGING_DIR")
REPOSITORY_DIR=$(dirname -- "$AGENT_DIR")
readonly PACKAGING_DIR AGENT_DIR REPOSITORY_DIR
readonly VERSION_PACKAGE=github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/version

commit=$(git -C "$REPOSITORY_DIR" rev-parse HEAD 2>/dev/null || echo unknown)
mkdir -p "$out/systemd"
out=$(cd -- "$out" && pwd)

for arch in amd64 arm64; do
    # Static and reproducible: no cgo, no build paths, no build id.
    (cd "$AGENT_DIR" && CGO_ENABLED=0 GOOS=linux GOARCH=$arch go build -trimpath \
        -ldflags "-s -w -buildid= -X $VERSION_PACKAGE.Version=$version -X $VERSION_PACKAGE.Commit=$commit" \
        -o "$out/mucki-agent-linux-$arch" ./cmd/mucki-agent)
done

install -m 0755 "$PACKAGING_DIR/install.sh" "$PACKAGING_DIR/uninstall.sh" "$out/"
install -m 0644 "$PACKAGING_DIR/systemd/mucki-agent.service" "$out/systemd/"
install -m 0644 "$AGENT_DIR/config.example.yaml" "$out/config.example.yaml"
install -m 0644 "$REPOSITORY_DIR/examples/policies/linux-host.yaml" "$out/policy.example.yaml"

(cd "$out" && sha256sum mucki-agent-linux-amd64 mucki-agent-linux-arm64 install.sh uninstall.sh \
    systemd/mucki-agent.service config.example.yaml policy.example.yaml > SHA256SUMS)
echo "built mucki-agent $version ($commit) in $out"
