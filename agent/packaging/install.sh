#!/usr/bin/env bash
# Installs or updates the MuckiSafe host agent on a Linux host with systemd.
# Idempotent: rerun it after an update of the binary or after adding a path
# or destination to the policy (they become writable for the agent).
#
#   install.sh [--binary PATH] [--container NAME]... [--no-start]
#
# --binary     agent binary; default: mucki-agent-linux-<arch> or mucki-agent
#              next to this script. A SHA256SUMS file next to the binary is
#              checked when present.
# --container  container that mounts the wakeup directory and must run
#              www-data with GID 33; repeatable. Without it, running
#              containers that mount the wakeup directory are detected.
# --no-start   install everything but do not (re)start the service.
#
# Never creates the secret: provision the agent in the MuckiSafe
# administration, store the one-time secret in /etc/mucki-agent/secret
# (root:root, 0600) and fill in /etc/mucki-agent/config.yaml, then rerun.
set -euo pipefail

readonly BINARY_TARGET=/usr/local/bin/mucki-agent
readonly CONFIG_DIR=/etc/mucki-agent
readonly CONFIG_FILE=$CONFIG_DIR/config.yaml
readonly POLICY_FILE=$CONFIG_DIR/policy.yaml
readonly LIB_DIR=/var/lib/mucki-agent
readonly STATE_DIR=$LIB_DIR/state
readonly WAKEUP_DIR=$LIB_DIR/wakeup
# www-data of the MuckiSafe container writes wakeup files: GID 33 is the
# contract between host and container, independent of host group names.
readonly WAKEUP_GID=33
readonly UNIT_FILE=/etc/systemd/system/mucki-agent.service
readonly DROPIN_DIR=/etc/systemd/system/mucki-agent.service.d
readonly DROPIN_FILE=$DROPIN_DIR/10-destinations.conf

SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
BINARY=""
CONTAINERS=()
START=true

die() {
    echo "install.sh: $*" >&2
    exit 1
}

info() {
    echo "install.sh: $*"
}

parse_args() {
    while [ $# -gt 0 ]; do
        case $1 in
            --binary) [ $# -ge 2 ] || die "--binary needs a path"; BINARY=$2; shift 2 ;;
            --container) [ $# -ge 2 ] || die "--container needs a name"; CONTAINERS+=("$2"); shift 2 ;;
            --no-start) START=false; shift ;;
            -h|--help) sed -n '2,18p' "$0"; exit 0 ;;
            *) die "unknown argument: $1" ;;
        esac
    done
}

require_host() {
    [ "$(id -u)" -eq 0 ] || die "must run as root"
    [ "$(uname -s)" = Linux ] || die "only Linux hosts are supported"
    command -v systemctl >/dev/null || die "systemd is required"
    [ -d /run/systemd/system ] || die "systemd is not the running init system"
}

architecture() {
    case $(uname -m) in
        x86_64|amd64) echo amd64 ;;
        aarch64|arm64) echo arm64 ;;
        *) die "unsupported architecture $(uname -m)" ;;
    esac
}

resolve_binary() {
    if [ -z "$BINARY" ]; then
        BINARY=$SCRIPT_DIR/mucki-agent-linux-$(architecture)
        [ -f "$BINARY" ] || BINARY=$SCRIPT_DIR/mucki-agent
    fi
    [ -f "$BINARY" ] && [ ! -L "$BINARY" ] || die "agent binary not found: $BINARY"
    local dir name
    dir=$(dirname -- "$BINARY")
    name=$(basename -- "$BINARY")
    if [ -f "$dir/SHA256SUMS" ]; then
        grep -qE "^[0-9a-f]{64}  \*?$name\$" "$dir/SHA256SUMS" || die "$name is not listed in $dir/SHA256SUMS"
        (cd -- "$dir" && sha256sum --check --ignore-missing --status SHA256SUMS) || die "checksum of $name does not match SHA256SUMS"
        info "checksum of $name verified"
    fi
    "$BINARY" --version >/dev/null || die "$BINARY does not run on this host"
}

# The wakeup directory is a bind mount into the MuckiSafe containers; their
# www-data must have GID 33, otherwise wakeups cannot be written.
check_wakeup_contract() {
    local containers=("${CONTAINERS[@]}")
    if [ ${#containers[@]} -eq 0 ] && command -v docker >/dev/null; then
        mapfile -t containers < <(detect_wakeup_containers)
    fi
    if [ ${#containers[@]} -eq 0 ]; then
        info "no container mounts $WAKEUP_DIR yet; mount it into the MuckiSafe app and scheduler containers (www-data, GID $WAKEUP_GID)"
        return
    fi
    local container gid
    for container in "${containers[@]}"; do
        gid=$(docker exec "$container" id -g www-data 2>/dev/null) || die "cannot read the www-data group of container $container"
        [ "$gid" = "$WAKEUP_GID" ] || die "www-data in container $container has GID $gid, the wakeup contract requires $WAKEUP_GID"
        info "container $container: www-data has GID $WAKEUP_GID"
    done
}

detect_wakeup_containers() {
    local ids
    ids=$(docker ps -q 2>/dev/null) || return 0
    [ -n "$ids" ] || return 0
    # shellcheck disable=SC2086 # ids is a whitespace-separated id list
    docker inspect --format '{{.Name}}{{range .Mounts}} {{.Source}}{{end}}' $ids |
        awk -v dir="$WAKEUP_DIR" '{ for (i = 2; i <= NF; i++) if ($i == dir) { sub(/^\//, "", $1); print $1 } }'
}

install_binary() {
    local temporary=$BINARY_TARGET.new.$$
    install -o root -g root -m 0755 "$BINARY" "$temporary"
    mv -f "$temporary" "$BINARY_TARGET"
    info "installed $("$BINARY_TARGET" --version)"
}

ensure_directory() {
    local path=$1 group=$2 mode=$3
    [ ! -L "$path" ] || die "$path must not be a symlink"
    install -d -o root -g "$group" -m "$mode" "$path"
}

create_directories() {
    ensure_directory "$CONFIG_DIR" root 0755
    ensure_directory "$LIB_DIR" root 0755
    ensure_directory "$STATE_DIR" root 0700
    ensure_directory "$WAKEUP_DIR" "$WAKEUP_GID" 0770
}

# Templates are installed once and never overwrite local changes.
install_defaults() {
    local example=$SCRIPT_DIR/config.example.yaml
    if [ ! -f "$example" ]; then
        example=$SCRIPT_DIR/../config.example.yaml
    fi
    if [ ! -e "$CONFIG_FILE" ]; then
        [ -f "$example" ] || die "config.example.yaml not found next to install.sh"
        install -o root -g root -m 0600 "$example" "$CONFIG_FILE"
        info "created $CONFIG_FILE from the example; set server.url and server.keyId"
    fi
    if [ ! -e "$POLICY_FILE" ]; then
        printf '# Local alias policy, see examples/policies/linux-host.yaml.\nversion: 1\n' > "$POLICY_FILE.new.$$"
        install -o root -g root -m 0644 "$POLICY_FILE.new.$$" "$POLICY_FILE"
        rm -f "$POLICY_FILE.new.$$"
        info "created an empty $POLICY_FILE"
    fi
}

install_unit() {
    local unit=$SCRIPT_DIR/systemd/mucki-agent.service
    [ -f "$unit" ] || die "systemd unit not found: $unit"
    install -o root -g root -m 0644 "$unit" "$UNIT_FILE.new.$$"
    mv -f "$UNIT_FILE.new.$$" "$UNIT_FILE"
}

write_destinations_dropin() {
    local lines
    lines=$("$BINARY_TARGET" policy writable-paths --config "$CONFIG_FILE" --format systemd) ||
        die "cannot derive the writable paths from $POLICY_FILE"
    install -d -o root -g root -m 0755 "$DROPIN_DIR"
    {
        echo "# Generated by install.sh from $POLICY_FILE; rerun install.sh after changing paths or destinations."
        echo "[Service]"
        [ -z "$lines" ] || echo "$lines"
    } > "$DROPIN_FILE.new.$$"
    chmod 0644 "$DROPIN_FILE.new.$$"
    mv -f "$DROPIN_FILE.new.$$" "$DROPIN_FILE"
    info "policy paths writable for the agent: $(echo "$lines" | grep -c . || true)"
}

start_service() {
    systemctl daemon-reload
    systemctl enable mucki-agent.service >/dev/null
    if [ "$START" != true ]; then
        info "installed; not started (--no-start)"
        return
    fi
    systemctl restart mucki-agent.service
    sleep 2
    systemctl is-active --quiet mucki-agent.service ||
        die "mucki-agent did not stay active; see: journalctl -u mucki-agent"
    info "mucki-agent is running"
}

main() {
    parse_args "$@"
    require_host
    resolve_binary
    check_wakeup_contract
    install_binary
    create_directories
    install_defaults
    install_unit
    if ! "$BINARY_TARGET" config validate --config "$CONFIG_FILE" >/dev/null; then
        systemctl daemon-reload
        if systemctl is-active --quiet mucki-agent.service; then
            die "configuration is invalid; the running agent keeps its previous configuration"
        fi
        info "not started: provision the agent, store the secret and complete $CONFIG_FILE, then rerun install.sh"
        return 0
    fi
    write_destinations_dropin
    start_service
}

main "$@"
