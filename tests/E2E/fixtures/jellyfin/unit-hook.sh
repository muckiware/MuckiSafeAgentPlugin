#!/bin/sh
# Test-only systemd hook of the Jellyfin E2E (tests/E2E/scenarios/jellyfin.sh).
# The scenario copies it to WORK_DIR/jellyfin/unit-hook.sh and references it
# from runtime drop-ins of jellyfin.service (ExecStop=+, ExecStopPost=+,
# ExecStartPre=+, i.e. as root). Never installed outside an E2E run.
#
#   unit-hook.sh stall FLAG     blocks while FLAG exists (scenario_stall: the
#                               stop of the pre phase hangs before Jellyfin
#                               receives any signal)
#   unit-hook.sh inject PLAN    applies a fixture path injection once the unit's
#                               processes are gone (ExecStopPost)
#   unit-hook.sh restore PLAN   undoes it before the next start (ExecStartPre)
#                               and from scenario_reset
#
# PLAN lines (tab separated): "remove HOST ASIDE" or "symlink HOST ASIDE TARGET".
# HOST is beneath a Jellyfin policy root, ASIDE beneath the directory "aside"
# next to PLAN (in WORK_DIR) on the same
# file system (checked by the scenario), so every move is a rename(2) that
# keeps inode, owner, mode and ACL of the original.
set -eu

tab=$(printf '\t')

stall() {
    while [ -e "$1" ]; do
        sleep 0.2
    done
}

inject() {
    [ -f "$1" ] || exit 0
    while IFS=$tab read -r kind host aside target; do
        [ -n "$kind" ] || continue
        # Applied once: a second stop must not move the restored tree again.
        if [ -e "$aside" ] || [ -L "$aside" ]; then
            continue
        fi
        mkdir -p "$(dirname "$aside")"
        mv "$host" "$aside"
        if [ "$kind" = symlink ]; then
            ln -s "$target" "$host"
        fi
    done <"$1"
}

restore() {
    [ -f "$1" ] || exit 0
    displaced=$(dirname "$1")/displaced
    while IFS=$tab read -r kind host aside target; do
        [ -n "$kind" ] || continue
        if [ ! -e "$aside" ] && [ ! -L "$aside" ]; then
            continue
        fi
        if [ -L "$host" ]; then
            rm -f "$host"
        fi
        # Anything else at the original path (for example a database the
        # service created meanwhile) is kept for the report, never merged.
        if [ -e "$host" ]; then
            mkdir -p "$displaced"
            mv "$host" "$displaced/$(date +%s)-$(basename "$host")"
        fi
        mv "$aside" "$host"
    done <"$1"
    # Only the now empty parents of the moved objects remain; a later case
    # must not mistake them for an applied injection.
    if [ -d "$(dirname "$1")/aside" ]; then
        find "$(dirname "$1")/aside" -depth -type d -empty -delete
    fi
}

case ${1:-} in
    stall) stall "$2" ;;
    inject) inject "$2" ;;
    restore) restore "$2" ;;
    *) echo "usage: $0 stall FLAG | inject PLAN | restore PLAN" >&2; exit 2 ;;
esac
