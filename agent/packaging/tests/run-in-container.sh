#!/usr/bin/env bash
# Runs hardening-e2e.sh on a developer machine: builds the agent and the fake
# control plane for the Docker host's architecture and starts the test in a
# disposable, privileged Ubuntu container with systemd as PID 1. Containers
# of the test run on the host's Docker daemon through its socket.
#
#   agent/packaging/tests/run-in-container.sh [--release]
#
# --release builds the release directory with build-release.sh and tests its
# binary, so install.sh also verifies it against SHA256SUMS (like CI does
# for a release).
set -euo pipefail

AGENT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)
readonly AGENT_DIR
readonly IMAGE=muwa-e2e-systemd:local
readonly NAME=muwa-e2e-systemd

arch=$(docker version --format '{{.Server.Arch}}')
# Below the agent directory: Docker Desktop shares it, unlike mktemp's /var/folders.
build=$AGENT_DIR/.e2e-build
rm -rf "$build"
mkdir -p "$build"
trap 'docker rm -f "$NAME" >/dev/null 2>&1 || true; rm -rf "$build"' EXIT

if [ "${1:-}" = --release ]; then
    "$AGENT_DIR/packaging/build-release.sh" 0.0.0-local "$build/release" >/dev/null
    binary=/build/release/mucki-agent-linux-$arch
else
    (cd "$AGENT_DIR" && CGO_ENABLED=0 GOOS=linux GOARCH=$arch go build -trimpath -o "$build/mucki-agent" ./cmd/mucki-agent)
    binary=/build/mucki-agent
fi
(cd "$AGENT_DIR" && CGO_ENABLED=0 GOOS=linux GOARCH=$arch go build -trimpath -o "$build/fakeplane" ./internal/e2e/fakeplane)

docker build -q -t "$IMAGE" - >/dev/null <<'EOF'
FROM ubuntu:24.04
RUN apt-get update \
 && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends systemd systemd-sysv docker.io jq curl util-linux ca-certificates \
 && systemctl mask docker.service docker.socket containerd.service \
 && rm -rf /var/lib/apt/lists/*
STOPSIGNAL SIGRTMIN+3
CMD ["/sbin/init"]
EOF

docker rm -f "$NAME" >/dev/null 2>&1 || true
docker run -d --name "$NAME" --privileged --cgroupns=host \
    -v /sys/fs/cgroup:/sys/fs/cgroup:rw \
    -v /var/run/docker.sock:/var/run/docker.sock \
    -v "$AGENT_DIR:/agent:ro" -v "$build:/build:ro" \
    --tmpfs /run --tmpfs /run/lock "$IMAGE" >/dev/null
for _ in $(seq 1 30); do
    state=$(docker exec "$NAME" systemctl is-system-running 2>/dev/null || true)
    case $state in running|degraded) break ;; esac
    sleep 1
done

docker exec "$NAME" /agent/packaging/tests/hardening-e2e.sh --binary "$binary" --fakeplane /build/fakeplane
