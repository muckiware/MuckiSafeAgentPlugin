#!/usr/bin/env bash
# Linux service E2E of MuckiSafe, Facility, AgentPlugin and the packaged host
# agent (Task 32). Runs one reference service on one native architecture:
#
#   tests/E2E/scripts/run-linux-e2e.sh --arch amd64|arm64 --scenario NAME [options]
#
#   --scenario         bitwarden | jellyfin | immich | paperless
#   --arch             must equal the host architecture (QEMU never counts)
#   --muckisafe-mode   source (default): pinned MUCKISAFE_BASE_IMAGE plus this
#                      plugin from source; release: MUWA_E2E_MUCKISAFE_IMAGE
#   --agent-mode       source (default): agent built with Go; release: the
#                      tarball MUWA_E2E_AGENT_TARBALL (+ .sha256)
#   --out DIR          artifacts (default tests/E2E/out/<arch>/<scenario>)
#   --keep             no teardown at the end (debugging)
#   --dry-run          print the plan and the cases, change nothing
#
# Gate E only accepts --muckisafe-mode release --agent-mode release runs.
# The host is changed (systemd unit, /etc/hosts, CA store, iptables,
# /srv/muwa-e2e): run as root on a disposable runner or VM only.
#
# Steps: preflight, stack, agent, service, backup, cases (every fixture case),
# resilience (generic fault injection), transport (explicit HTTP and its
# warning), upgrade (docker compose pull && up -d keeps the host side),
# facility-only (backups without the AgentPlugin), cleanup. Writes JUnit XML,
# container/agent logs, run/job states and file manifests to --out.

# Steps and cases are invoked indirectly through check/infra.
# shellcheck disable=SC2329
set -euo pipefail

SCRIPTS_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
readonly SCRIPTS_DIR
readonly SERVICES="bitwarden jellyfin immich paperless"

E2E_ARCH=""
SCENARIO_NAME=""
OUT_DIR=""
KEEP=false
E2E_DRY_RUN=0
MUCKISAFE_MODE=source
AGENT_MODE=source

usage() {
    sed -n '2,24p' "$0" >&2
    exit 2
}

parse_args() {
    while [ $# -gt 0 ]; do
        case $1 in
            --arch) [ $# -ge 2 ] || usage; E2E_ARCH=$2; shift 2 ;;
            --scenario) [ $# -ge 2 ] || usage; SCENARIO_NAME=$2; shift 2 ;;
            --out) [ $# -ge 2 ] || usage; OUT_DIR=$2; shift 2 ;;
            --muckisafe-mode) [ $# -ge 2 ] || usage; MUCKISAFE_MODE=$2; shift 2 ;;
            --agent-mode) [ $# -ge 2 ] || usage; AGENT_MODE=$2; shift 2 ;;
            --keep) KEEP=true; shift ;;
            --dry-run) E2E_DRY_RUN=1; shift ;;
            -h|--help) usage ;;
            *) echo "unknown argument: $1" >&2; usage ;;
        esac
    done
    case $E2E_ARCH in amd64|arm64) ;; *) echo "--arch must be amd64 or arm64" >&2; exit 2 ;; esac
    case " $SERVICES " in *" $SCENARIO_NAME "*) ;; *) echo "--scenario must be one of: $SERVICES" >&2; exit 2 ;; esac
    case $MUCKISAFE_MODE in source|release) ;; *) echo "--muckisafe-mode must be source or release" >&2; exit 2 ;; esac
    case $AGENT_MODE in source|release) ;; *) echo "--agent-mode must be source or release" >&2; exit 2 ;; esac
    OUT_DIR=${OUT_DIR:-}
}

load_modules() {
    # shellcheck source=lib.sh
    . "$SCRIPTS_DIR/lib.sh"
    OUT_DIR=${OUT_DIR:-$E2E_DIR/out/$E2E_ARCH/$SCENARIO_NAME}
    mkdir -p "$OUT_DIR"
    OUT_DIR=$(cd -- "$OUT_DIR" && pwd)
    # shellcheck source=setup.sh
    . "$SCRIPTS_DIR/setup.sh"
    # shellcheck source=trigger-backup.sh
    . "$SCRIPTS_DIR/trigger-backup.sh"
    # shellcheck source=assert-effects.sh
    . "$SCRIPTS_DIR/assert-effects.sh"
    # shellcheck source=inject-failure.sh
    . "$SCRIPTS_DIR/inject-failure.sh"
    # shellcheck source=cleanup.sh
    . "$SCRIPTS_DIR/cleanup.sh"
    # shellcheck disable=SC1090
    . "$E2E_DIR/scenarios/$SCENARIO_NAME.sh"
    load_pins
    [ -n "${SCENARIO_SUCCESS_CASE:-}" ] || die "scenarios/$SCENARIO_NAME.sh sets no SCENARIO_SUCCESS_CASE"
    check_paths
}

# The teardown removes WORK_DIR recursively: it must be a dedicated
# directory, and the artifacts must live outside of it.
check_paths() {
    case $WORK_DIR in
        /srv/?*|/var/tmp/?*|/tmp/?*) ;;
        *) die "MUWA_E2E_WORK_DIR must be a dedicated directory below /srv, /var/tmp or /tmp: '$WORK_DIR'" ;;
    esac
    case $WORK_DIR in *..*) die "MUWA_E2E_WORK_DIR must not contain '..'" ;; esac
    case $OUT_DIR/ in "$WORK_DIR"/*) die "--out must not be inside $WORK_DIR (the teardown removes it)" ;; esac
}

host_arch() {
    case $(uname -m) in
        x86_64|amd64) echo amd64 ;;
        aarch64|arm64) echo arm64 ;;
        *) uname -m ;;
    esac
}

# --- steps ----------------------------------------------------------------------

preflight() {
    [ "$(id -u)" -eq 0 ] || die "must run as root"
    [ "$(uname -s)" = Linux ] || die "Linux only"
    [ "$(host_arch)" = "$E2E_ARCH" ] || die "--arch $E2E_ARCH on a $(host_arch) host: only native runs count"
    [ -d /run/systemd/system ] || die "systemd must be the running init system"
    local tool
    for tool in docker jq curl git iptables getfacl setfacl setpriv flock sha256sum update-ca-certificates; do
        command -v "$tool" >/dev/null || die "missing tool: $tool"
    done
    docker compose version >/dev/null || die "docker compose plugin missing"
    [ "$AGENT_MODE" = release ] || command -v go >/dev/null || die "--agent-mode source needs Go"
    "$SCRIPTS_DIR/selftest.sh" >/dev/null || die "harness selftest failed"
    if [ -e /etc/mucki-agent ] && [ ! -e /etc/mucki-agent/.muwa-e2e ]; then
        die "this host has a real MuckiSafe agent (/etc/mucki-agent); run the E2E on a disposable host"
    fi
    if [ -e "$WORK_DIR" ] && [ ! -e "$WORK_DIR/.muwa-e2e" ]; then
        die "$WORK_DIR exists and was not created by this E2E"
    fi
    teardown
}

run_fixture_case() {
    local name=$1
    scenario_inject "$name"
    backup_trigger
    assert_case "$name"
    scenario_assert_effects "$name"
    scenario_reset "$name"
    assert_no_open_jobs
}

# Explicit HTTP: same signatures, a successful backup and the operator
# warning in the agent journal; then back to HTTPS.
transport_http_check() {
    local mark
    agent_write_config http
    mark=$(journal_mark)
    agent_mark_restart
    systemctl restart "$AGENT_UNIT"
    wait_for 60 "agent host active over HTTP" agent_active
    assert_contains "$(agent_journal_since "$mark")" "actions, parameters, results and error output can be read on the network" "HTTP warning"
    scenario_inject "$SCENARIO_SUCCESS_CASE"
    backup_trigger
    assert_case "$SCENARIO_SUCCESS_CASE"
    scenario_assert_effects "$SCENARIO_SUCCESS_CASE"
    scenario_reset "$SCENARIO_SUCCESS_CASE"
    agent_write_config https
    mark=$(journal_mark)
    agent_mark_restart
    systemctl restart "$AGENT_UNIT"
    wait_for 60 "agent host active over HTTPS" agent_active
    assert_not_contains "$(agent_journal_since "$mark")" "uses plain HTTP" "no HTTP warning over HTTPS"
}

# host_side_fingerprint: the host side of the agent. The secret's hash stays
# in WORK_DIR (compared there), the artifact copy omits it.
host_side_fingerprint() {
    sha256sum /usr/local/bin/mucki-agent "$AGENT_CONFIG" "$AGENT_SECRET" "$AGENT_POLICY"
    stat -c '%n %U:%G %a' "$AGENT_STATE" "$WAKEUP_HOST_DIR"
    find "$AGENT_STATE" -printf '%P %y %m %s\n' | LC_ALL=C sort
    local service
    for service in app scheduler; do
        docker inspect -f '{{range .Mounts}}{{if eq .Destination "/var/run/mucki-agent/wakeup"}}{{.Source}}{{end}}{{end}}' "$(stack ps -q "$service")"
    done
}

# docker compose pull && docker compose up -d keeps binary, config, secret,
# journal and wakeup path; the agent keeps working afterwards.
upgrade_check() {
    host_side_fingerprint >"$WORK_DIR/host-before-upgrade.txt"
    if [ "$MUCKISAFE_MODE" = release ]; then
        stack pull
        stack up -d
    else
        stack up -d --force-recreate
    fi
    wait_for 600 "MuckiSafe app healthy after the update" app_healthy
    API_TOKEN=""
    host_side_fingerprint >"$WORK_DIR/host-after-upgrade.txt"
    mkdir -p "$OUT_DIR/manifests"
    grep -vF "$AGENT_SECRET" "$WORK_DIR/host-after-upgrade.txt" >"$OUT_DIR/manifests/host-after-upgrade.txt"
    if ! cmp -s "$WORK_DIR/host-before-upgrade.txt" "$WORK_DIR/host-after-upgrade.txt"; then
        diff -u "$WORK_DIR/host-before-upgrade.txt" "$WORK_DIR/host-after-upgrade.txt" | grep -vF "$AGENT_SECRET" >&2 || true
        log "assertion failed: the update changed the host side"
        return 1
    fi
    agent_mark_restart
    wait_for 60 "agent host active after the update" agent_active
    run_fixture_case "$SCENARIO_SUCCESS_CASE"
}

# Facility without the AgentPlugin: with the plugin deactivated a backup of a
# readable path exits 0, writes a snapshot and creates no agent run. (A stack
# that never had the plugin is MuckiFacilityPlugin's own CI; this proves the
# plugin leaves nothing behind that a Facility backup depends on.)
facility_only_check() {
    local runs_before payload repository password snapshots
    runs_before=$(sql "SELECT COUNT(*) FROM muwa_agent_backup_run")
    console plugin:deactivate MuckiSafeAgentPlugin
    console cache:clear >/dev/null
    repository=$(uuid)
    password=$(random_hex 16)
    payload=$(jq -nc --arg id "$repository" --arg pw "$password" --arg path "$CONTAINER_REPOS_DIR/facility-only-$repository" \
        --arg pid "$(uuid)" \
        '{id: $id, active: true, name: "e2e-facility-only", type: "noneDatabase", hostname: "e2e", repositoryPath: $path,
          repositoryPassword: $pw, repositoryRepeatPassword: $pw, preBackupCommandType: "none", postBackupCommandType: "none",
          backupPaths: [{id: $pid, backupPath: "/var/www/html/config", compress: false, position: 0, isDefault: false}]}')
    api POST /api/_action/muwa/backup/repository/init "$payload" >/dev/null
    console muckiware:backup:create "$repository"
    assert_eq "$(sql "SELECT COUNT(*) FROM muwa_agent_backup_run")" "$runs_before" "agent runs without the AgentPlugin"
    snapshots=$(stack exec -T -u www-data -e RESTIC_CACHE_DIR=/tmp/restic-cache -e RESTIC_PASSWORD="$password" app \
        restic -r "$CONTAINER_REPOS_DIR/facility-only-$repository" snapshots --json </dev/null | jq 'length')
    assert_eq "$snapshots" 1 "snapshots of the Facility-only backup"
    console plugin:activate MuckiSafeAgentPlugin
    console cache:clear >/dev/null
}

# --- driver ---------------------------------------------------------------------

# infra STEP DESCRIPTION FUNCTION: a setup step; a failure aborts the run.
infra() {
    local step=$1 description=$2
    shift 2
    plan "$step" "$description"
    is_dry_run && return 0
    check "$SCENARIO_NAME.$step" "$description" "$@"
    [ "$CHECK_STATUS" -eq 0 ] || finish 1
}

# case STEP NAME FUNCTION ARGS...: one test case; after a failure everything
# injected is reset so the next case starts clean.
run_case() {
    check "$@"
    if [ "$CHECK_STATUS" -ne 0 ] && ! is_dry_run; then
        inject_reset_all || true
        scenario_reset "$SCENARIO_SUCCESS_CASE" >/dev/null 2>&1 || log "scenario reset after a failed case failed too"
    fi
}

# run_info: what exactly was tested (Gate E evidence).
run_info() {
    local image digest agent tarball_sha=""
    image=$(env_value MUWA_E2E_APP_IMAGE 2>/dev/null || true)
    digest=$(docker image inspect -f '{{join .RepoDigests ","}} {{.Id}}' "$image" 2>/dev/null || true)
    agent=$(/usr/local/bin/mucki-agent --version 2>/dev/null || true)
    if [ "$AGENT_MODE" = release ] && [ -f "${MUWA_E2E_AGENT_TARBALL:-}" ]; then
        tarball_sha=$(sha256sum "$MUWA_E2E_AGENT_TARBALL" | cut -c1-64)
    fi
    jq -n --arg scenario "$SCENARIO_NAME" --arg arch "$E2E_ARCH" --arg ms "$MUCKISAFE_MODE" --arg am "$AGENT_MODE" \
        --arg image "$image" --arg digest "$digest" --arg agent "$agent" --arg tarball "$tarball_sha" \
        --arg compose "${MUCKISAFE_COMPOSE_REF:-}" --arg commit "$(git -C "$REPO_ROOT" rev-parse HEAD 2>/dev/null || true)" \
        '{scenario: $scenario, arch: $arch, muckisafeMode: $ms, agentMode: $am, muckisafeImage: $image,
          muckisafeImageDigest: $digest, agentVersion: $agent, agentTarballSha256: $tarball,
          muckisafeComposeRef: $compose, pluginCommit: $commit,
          gateE: ($ms == "release" and $am == "release")}' >"$OUT_DIR/run-info.json"
}

finish() {
    local status=$1
    junit_write
    if ! is_dry_run; then
        run_info || true
        collect_artifacts
        [ "$KEEP" = true ] || teardown
    fi
    trap - EXIT
    [ "$JUNIT_FAILURES" -eq 0 ] || status=1
    log "$SCENARIO_NAME/$E2E_ARCH: $JUNIT_TESTS cases, $JUNIT_FAILURES failed, $JUNIT_SKIPPED skipped"
    exit "$status"
}

# The case lists are read from fd 3, so nothing a case runs can consume them.
run_cases() {
    local name function expected
    expected=$((JUNIT_TESTS + $(scenario_cases | grep -c .) + $(resilience_cases | grep -c .) + 3))
    plan cases "every case of fixtures/$SCENARIO_NAME/fixture.json"
    while IFS= read -r name <&3; do
        run_case "$SCENARIO_NAME.cases" "$name" run_fixture_case "$name"
    done 3< <(scenario_cases)
    plan resilience "generic fault injection with '$SCENARIO_SUCCESS_CASE'"
    while IFS=$'\t' read -r name function <&3; do
        run_case "$SCENARIO_NAME.resilience" "$name" "$function"
    done 3< <(resilience_cases)
    plan transport "explicit HTTP: signatures and the visible warning"
    run_case "$SCENARIO_NAME.transport" "explicit HTTP" transport_http_check
    plan upgrade "docker compose pull && docker compose up -d keeps the host side"
    run_case "$SCENARIO_NAME.upgrade" "compose update keeps binary, config, secret, journal and wakeup path" upgrade_check
    plan facility-only "backups without the AgentPlugin"
    run_case "$SCENARIO_NAME.facility-only" "Facility backup without AgentPlugin" facility_only_check
    if [ "$JUNIT_TESTS" -ne "$expected" ]; then
        junit_case "$SCENARIO_NAME" "every planned case ran" 0 failure "expected $expected cases, recorded $JUNIT_TESTS"
    fi
}

main() {
    parse_args "$@"
    load_modules
    junit_begin "muckisafe-e2e.$SCENARIO_NAME.$E2E_ARCH" "$OUT_DIR/junit.xml"
    local reason
    reason=$(scenario_skip_reason "$E2E_ARCH")
    if [ -n "$reason" ]; then
        log "SKIP $SCENARIO_NAME on $E2E_ARCH: $reason"
        junit_case "$SCENARIO_NAME" "$SCENARIO_NAME on $E2E_ARCH" 0 skipped "$reason"
        E2E_DRY_RUN=1
        finish 0
    fi
    is_dry_run || trap 'finish 1' EXIT
    infra preflight "native $E2E_ARCH Linux host with systemd, docker and tools" preflight
    infra stack "MuckiSafe stack ($MUCKISAFE_MODE) with HTTPS proxy" stack_setup
    infra agent "packaged agent ($AGENT_MODE) under systemd, provisioned over HTTPS" agent_install
    infra service "pinned $SCENARIO_NAME in its documented start state" scenario_up
    infra backup "Facility repository, actions and hooks" backup_setup
    run_cases
    plan cleanup "artifacts, teardown"
    finish 0
}

# Every check runs in a subshell: state that later steps need lives in files
# under WORK_DIR (agent-host-id, agent-key-id, repository-id), not variables.
stack_setup() {
    stack_prepare
    stack_up
}

backup_setup() {
    scenario_seed
    backup_repository_create
    hooks_create
}

main "$@"
