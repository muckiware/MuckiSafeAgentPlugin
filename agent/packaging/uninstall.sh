#!/usr/bin/env bash
# Removes the MuckiSafe host agent service and binary.
#
#   uninstall.sh [--purge]
#
# Without --purge, configuration, secret, policy, journal and the wakeup
# directory stay in place (rollback keeps them until a legacy backup run
# succeeded). --purge also removes /etc/mucki-agent and the state directory,
# but refuses while the journal still holds job records; the wakeup
# directory is removed only when it is empty, because containers mount it.
set -euo pipefail

readonly BINARY_TARGET=/usr/local/bin/mucki-agent
readonly CONFIG_DIR=/etc/mucki-agent
readonly LIB_DIR=/var/lib/mucki-agent
readonly STATE_DIR=$LIB_DIR/state
readonly WAKEUP_DIR=$LIB_DIR/wakeup
readonly UNIT_FILE=/etc/systemd/system/mucki-agent.service
readonly DROPIN_DIR=/etc/systemd/system/mucki-agent.service.d

PURGE=false

die() {
    echo "uninstall.sh: $*" >&2
    exit 1
}

info() {
    echo "uninstall.sh: $*"
}

parse_args() {
    while [ $# -gt 0 ]; do
        case $1 in
            --purge) PURGE=true; shift ;;
            -h|--help) sed -n '2,11p' "$0"; exit 0 ;;
            *) die "unknown argument: $1" ;;
        esac
    done
}

# Open journal records mean a job whose completion did not reach the control
# plane yet; deleting them would lose that result.
check_journal_empty() {
    local jobs=$STATE_DIR/jobs
    if [ -d "$jobs" ] && find "$jobs" -maxdepth 1 -name '*.json' -print -quit | grep -q .; then
        die "the journal in $jobs still holds job records; start the agent until they are completed, then purge"
    fi
}

remove_service() {
    if systemctl list-unit-files mucki-agent.service >/dev/null 2>&1; then
        systemctl disable --now mucki-agent.service >/dev/null 2>&1 || true
    fi
    rm -f "$UNIT_FILE"
    rm -rf "$DROPIN_DIR"
    systemctl daemon-reload
    systemctl reset-failed mucki-agent.service >/dev/null 2>&1 || true
    rm -f "$BINARY_TARGET"
    info "service and binary removed"
}

purge() {
    rm -rf "$CONFIG_DIR" "$STATE_DIR"
    if rmdir "$WAKEUP_DIR" 2>/dev/null; then
        rmdir "$LIB_DIR" 2>/dev/null || true
    elif [ -d "$WAKEUP_DIR" ]; then
        info "kept $WAKEUP_DIR: it is not empty (remove the container mounts first)"
    fi
    info "configuration, secret, policy and journal removed"
}

main() {
    parse_args "$@"
    [ "$(id -u)" -eq 0 ] || die "must run as root"
    if [ "$PURGE" = true ]; then
        check_journal_empty
    fi
    remove_service
    if [ "$PURGE" = true ]; then
        purge
    else
        info "kept $CONFIG_DIR and $LIB_DIR"
    fi
}

main "$@"
