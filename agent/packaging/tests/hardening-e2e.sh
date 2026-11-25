#!/usr/bin/env bash
# E2E hardening test of the packaged agent on a real Linux host with systemd
# and docker (GitHub runner or a privileged systemd container). Run as root:
#
#   hardening-e2e.sh --binary PATH --fakeplane PATH
#
# It installs the agent with install.sh, runs it under the hardened
# mucki-agent.service against the fake control plane and proves that
#   - every V1 host function the reference actions need still works inside
#     the sandbox (systemctl, docker exec, write pipeline, retention, ACL and
#     readability checks), and the file system is read-only except the state
#     directory and the paths of the policy;
#   - the start is refused (exit 78, no restart loop) for an unsafe config,
#     secret, policy or state file or parent directory;
#   - reload, graceful stop with a running job, the GID 33 wakeup contract,
#     a repeated install and the uninstall behave as documented.
# The host is changed: run it only on a disposable machine or container.
set -euo pipefail

PACKAGING_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
readonly PACKAGING_DIR
readonly PLANE=http://127.0.0.1:18080
readonly DATA_DIR=/srv/muwa-e2e
readonly APP_CONTAINER=muwa-e2e-app
readonly FOREIGN_CONTAINER=muwa-e2e-foreign
readonly DUMMY_UNIT=muwa-e2e-dummy.service
readonly APP_IMAGE=${MUWA_E2E_APP_IMAGE:-debian:bookworm-slim}
readonly FOREIGN_IMAGE=${MUWA_E2E_FOREIGN_IMAGE:-alpine:3.20}

BINARY=""
FAKEPLANE=""
PLANE_PID=""
PASSED=0

parse_args() {
    while [ $# -gt 0 ]; do
        case $1 in
            --binary) BINARY=$(realpath "$2"); shift 2 ;;
            --fakeplane) FAKEPLANE=$(realpath "$2"); shift 2 ;;
            *) echo "unknown argument: $1" >&2; exit 2 ;;
        esac
    done
    [ -x "$BINARY" ] && [ -x "$FAKEPLANE" ] || { echo "usage: $0 --binary PATH --fakeplane PATH" >&2; exit 2; }
    [ "$(id -u)" -eq 0 ] || { echo "must run as root" >&2; exit 2; }
}

ok() {
    PASSED=$((PASSED + 1))
    echo "ok $PASSED - $*"
}

fail() {
    echo "not ok - $*" >&2
    echo "--- systemctl status" >&2
    systemctl status --no-pager mucki-agent.service >&2 || true
    echo "--- journal" >&2
    journalctl -u mucki-agent.service --no-pager -n 80 >&2 || true
    exit 1
}

# wait_for SECONDS DESCRIPTION COMMAND...
wait_for() {
    local seconds=$1 description=$2
    shift 2
    local deadline=$((SECONDS + seconds))
    until "$@"; do
        [ $SECONDS -lt "$deadline" ] || fail "timed out after ${seconds}s: $description"
        sleep 1
    done
}

status() {
    curl -fsS "$PLANE/_e2e/status"
}

plane_up() {
    curl -fs "$PLANE/_e2e/status" >/dev/null 2>&1
}

cleanup() {
    set +e
    [ -z "$PLANE_PID" ] || kill "$PLANE_PID" 2>/dev/null
    "$PACKAGING_DIR/uninstall.sh" --purge >/dev/null 2>&1 || {
        rm -rf /var/lib/mucki-agent/state/jobs
        "$PACKAGING_DIR/uninstall.sh" --purge >/dev/null 2>&1
    }
    docker rm -f "$APP_CONTAINER" "$FOREIGN_CONTAINER" >/dev/null 2>&1
    systemctl disable --now "$DUMMY_UNIT" >/dev/null 2>&1
    rm -f "/etc/systemd/system/$DUMMY_UNIT"
    systemctl daemon-reload
    rm -rf "$DATA_DIR" /var/lib/mucki-agent
}

# --- fixtures -------------------------------------------------------------

prepare_host() {
    printf '[Unit]\nDescription=MuckiSafe E2E dummy service\n[Service]\nExecStart=/bin/sleep infinity\n' > "/etc/systemd/system/$DUMMY_UNIT"
    systemctl daemon-reload
    systemctl start "$DUMMY_UNIT"
    install -d -o root -g root -m 0755 "$DATA_DIR" "$DATA_DIR/backups"
    echo "restore-critical" > "$DATA_DIR/data.txt"
    chmod 0644 "$DATA_DIR/data.txt"
    docker rm -f "$APP_CONTAINER" "$FOREIGN_CONTAINER" >/dev/null 2>&1 || true
    docker run -d --name "$APP_CONTAINER" -v /var/lib/mucki-agent/wakeup:/var/run/mucki-agent/wakeup "$APP_IMAGE" sleep infinity >/dev/null
    docker run -d --name "$FOREIGN_CONTAINER" "$FOREIGN_IMAGE" sleep 3600 >/dev/null
}

write_secret_and_config() {
    local secret key_id
    secret=$(head -c 32 /dev/urandom | base64 | tr '+/' '-_' | tr -d '=\n')
    key_id=$(head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n')
    install -o root -g root -m 0600 /dev/null /etc/mucki-agent/secret
    printf '%s\n' "$secret" > /etc/mucki-agent/secret
    cat > /etc/mucki-agent/config.yaml <<EOF
server:
  url: $PLANE
  allowInsecureHttp: true
  keyId: $key_id
  secretFile: /etc/mucki-agent/secret
agent:
  pollInterval: 2s
  heartbeatInterval: 2s
  leaseDuration: 10s
EOF
    chmod 0600 /etc/mucki-agent/config.yaml
    "$FAKEPLANE" --listen 127.0.0.1:18080 --key-id "$key_id" --secret-file /etc/mucki-agent/secret >/tmp/muwa-e2e-fakeplane.log 2>&1 &
    PLANE_PID=$!
    wait_for 10 "fake control plane listens" plane_up
}

write_policy() {
    cat > /etc/mucki-agent/policy.yaml <<EOF
version: 1
services:
  e2e-dummy:
    unit: $DUMMY_UNIT
    stopTimeoutSeconds: 30
    startTimeoutSeconds: 30
    requireEmptyCgroupAfterStop: true
containers:
  e2e-app:
    name: $APP_CONTAINER
commands:
  e2e-dump:
    containerAlias: e2e-app
    argv: [cat, /etc/os-release]
    timeoutSeconds: 60
  e2e-slow:
    containerAlias: e2e-app
    argv: [sleep, "600"]
    timeoutSeconds: 900
paths:
  e2e-data:
    root: $DATA_DIR
    recursive: true
principals:
  e2e-reader:
    uid: 33
    gid: 33
destinations:
  e2e-dumps:
    pathAlias: e2e-data
    directory: backups
    prefix: e2e-dump-
    suffix: .txt.gz
    owner: 33
    group: 33
    mode: "0640"
    maximumBytes: 1048576
    maximumKeep: 2
checks:
  e2e-current-output:
    destinationAlias: e2e-dumps
    readableBy: e2e-reader
    minimumSize: 1
  e2e-data-readable:
    pathAlias: e2e-data
    readableBy: e2e-reader
    relativePaths: [data.txt]
EOF
    chmod 0644 /etc/mucki-agent/policy.yaml
}

# enqueue DEFINITION_JSON [EXECUTION_SECONDS] prints the job id.
enqueue() {
    curl -fsS -X POST "$PLANE/_e2e/enqueue" -H 'Content-Type: application/json' \
        -d "{\"definition\":$1,\"executionSeconds\":${2:-120}}" | jq -r .jobId
}

completion_of() {
    status | jq -c --arg id "$1" '.completions[] | select(.jobId == $id)'
}

has_completion() {
    [ -n "$(completion_of "$1")" ]
}

agent_active() {
    systemctl is-active --quiet mucki-agent.service
}

agent_failed() {
    [ "$(systemctl show -p ActiveState --value mucki-agent.service)" = failed ]
}

# --- scenarios ------------------------------------------------------------

test_wakeup_contract_is_checked_before_anything_is_installed() {
    if "$PACKAGING_DIR/install.sh" --binary "$BINARY" --container "$FOREIGN_CONTAINER" >/tmp/muwa-e2e-install.log 2>&1; then
        fail "install.sh accepted a container whose www-data is not GID 33"
    fi
    grep -q "GID\|www-data" /tmp/muwa-e2e-install.log || fail "install.sh did not explain the GID mismatch: $(cat /tmp/muwa-e2e-install.log)"
    [ ! -e /usr/local/bin/mucki-agent ] || fail "binary was installed despite the failed GID check"
    ok "install.sh refuses a container without www-data GID 33 before installing anything"
}

test_install_without_secret_does_not_start() {
    "$PACKAGING_DIR/install.sh" --binary "$BINARY" >/tmp/muwa-e2e-install.log 2>&1 || fail "first install failed: $(cat /tmp/muwa-e2e-install.log)"
    grep -q "container $APP_CONTAINER: www-data has GID 33" /tmp/muwa-e2e-install.log || fail "wakeup container was not detected: $(cat /tmp/muwa-e2e-install.log)"
    grep -q "not started" /tmp/muwa-e2e-install.log || fail "install.sh should wait for the provisioning"
    ! agent_active || fail "agent must not run before it is provisioned"
    [ "$(stat -c '%u:%g %a' /var/lib/mucki-agent/wakeup)" = "0:33 770" ] || fail "wakeup directory: $(stat -c '%U:%G %a' /var/lib/mucki-agent/wakeup)"
    [ "$(stat -c '%u:%g %a' /var/lib/mucki-agent/state)" = "0:0 700" ] || fail "state directory: $(stat -c '%U:%G %a' /var/lib/mucki-agent/state)"
    [ "$(stat -c '%u:%g %a' /etc/mucki-agent/config.yaml)" = "0:0 600" ] || fail "config template permissions"
    ok "install.sh creates the directories and templates and waits for the provisioning"
}

test_provisioned_install_starts_the_hardened_service() {
    write_secret_and_config
    write_policy
    "$PACKAGING_DIR/install.sh" --binary "$BINARY" >/tmp/muwa-e2e-install.log 2>&1 || fail "install failed: $(cat /tmp/muwa-e2e-install.log)"
    agent_active || fail "agent is not active"
    grep -qx 'ReadWritePaths="-/srv/muwa-e2e"' /etc/systemd/system/mucki-agent.service.d/10-destinations.conf ||
        fail "destination drop-in: $(cat /etc/systemd/system/mucki-agent.service.d/10-destinations.conf)"
    wait_for 15 "host heartbeat" sh -c "curl -fsS $PLANE/_e2e/status | jq -e '.heartbeats | length > 0' >/dev/null"
    status | jq -e '.heartbeats[-1].capabilities | index("service.control") and index("file.write-pipeline")' >/dev/null ||
        fail "heartbeat capabilities: $(status | jq -c '.heartbeats[-1]')"
    local property actual
    for property in NoNewPrivileges=yes PrivateTmp=yes ProtectSystem=strict ProtectHome=read-only UMask=0077 \
        "RestrictAddressFamilies=AF_INET AF_INET6 AF_UNIX" CapabilityBoundingSet=cap_chown; do
        actual=$(systemctl show -p "${property%%=*}" --value mucki-agent.service)
        [[ $actual == "${property#*=}"* ]] || fail "unit property ${property%%=*} is '$actual', expected '${property#*=}'"
    done
    ok "provisioned install starts the hardened service, heartbeats reach the control plane"
}

test_the_sandbox_is_read_only_except_state_and_policy_paths() {
    local pid
    pid=$(systemctl show -p MainPID --value mucki-agent.service)
    nsenter -t "$pid" -m -- touch /var/lib/mucki-agent/state/.probe || fail "state directory is not writable for the agent"
    nsenter -t "$pid" -m -- touch "$DATA_DIR/backups/.probe" || fail "policy path is not writable for the agent"
    rm -f /var/lib/mucki-agent/state/.probe "$DATA_DIR/backups/.probe"
    local path
    for path in /etc/mucki-agent/.probe /usr/local/bin/.probe /srv/.probe /var/lib/mucki-agent/wakeup/.probe /var/lib/.probe /root/.probe; do
        if nsenter -t "$pid" -m -- touch "$path" 2>/dev/null; then
            rm -f "$path"
            fail "$path is writable inside the sandbox"
        fi
    done
    ok "only the state directory and the policy paths are writable inside the sandbox"
}

test_every_host_function_works_inside_the_sandbox() {
    local before job completion file
    before=$(systemctl show -p MainPID --value "$DUMMY_UNIT")
    job=$(enqueue '{"version":1,"steps":[
        {"primitive":"service.control","serviceAlias":"e2e-dummy","operation":"stop"},
        {"primitive":"service.control","serviceAlias":"e2e-dummy","operation":"start"},
        {"primitive":"docker.container.exec","containerAlias":"e2e-app","commandAlias":"e2e-dump","arguments":{}},
        {"primitive":"file.write-pipeline","sourceCommandAlias":"e2e-dump","destinationAlias":"e2e-dumps","transforms":["gzip"],"nameFromRunId":true},
        {"primitive":"file.retention","pathAlias":"e2e-data","manifestAlias":"e2e-dumps","keep":2},
        {"primitive":"filesystem.acl.read","pathAlias":"e2e-data","principalAlias":"e2e-reader"},
        {"primitive":"filesystem.assert-readable","checkAlias":"e2e-current-output"},
        {"primitive":"filesystem.assert-readable","checkAlias":"e2e-data-readable"}]}')
    wait_for 60 "completion of $job" has_completion "$job"
    completion=$(completion_of "$job")
    [ "$(jq -r .outcome <<<"$completion")" = succeeded ] || fail "host functions failed in the sandbox: $completion"
    [ "$(systemctl show -p MainPID --value "$DUMMY_UNIT")" != "$before" ] || fail "dummy service was not restarted"
    file=$DATA_DIR/backups/e2e-dump-$job.txt.gz
    [ "$(stat -c '%u:%g %a' "$file")" = "33:33 640" ] || fail "published backup: $(stat -c '%u:%g %a' "$file" 2>&1)"
    zcat "$file" | grep -q '^ID=' || fail "published backup does not contain the command output"
    [ -z "$(ls -A /var/lib/mucki-agent/state/jobs)" ] || fail "journal record left after an accepted completion"
    ok "systemctl, docker exec, write pipeline, retention, ACL and readability checks work inside the sandbox"
}

test_reload_keeps_the_previous_policy_on_error() {
    cp /etc/mucki-agent/policy.yaml /tmp/muwa-e2e-policy.yaml
    printf 'version: 1\nshell:\n  any: /bin/sh\n' > /etc/mucki-agent/policy.yaml
    systemctl reload mucki-agent.service
    wait_for 10 "failed reload is logged" sh -c "journalctl -u mucki-agent.service --no-pager | grep -q 'policy reload failed, previous policy stays active'"
    agent_active || fail "an invalid policy reload stopped the agent"
    cp /tmp/muwa-e2e-policy.yaml /etc/mucki-agent/policy.yaml
    systemctl reload mucki-agent.service
    wait_for 10 "reload is logged" sh -c "journalctl -u mucki-agent.service --no-pager | grep -q 'policy reloaded'"
    ok "systemctl reload reloads the policy and keeps the previous one on error"
}

test_stop_finishes_the_running_job_within_the_stop_budget() {
    local job started completion
    job=$(enqueue '{"version":1,"steps":[{"primitive":"docker.container.exec","containerAlias":"e2e-app","commandAlias":"e2e-slow","arguments":{}}]}' 600)
    wait_for 30 "start of $job" sh -c "curl -fsS $PLANE/_e2e/status | jq -e --arg id '$job' '.started | index(\$id)' >/dev/null"
    sleep 2
    started=$SECONDS
    systemctl stop mucki-agent.service
    [ $((SECONDS - started)) -lt 90 ] || fail "stop took $((SECONDS - started))s, more than TimeoutStopSec"
    completion=$(completion_of "$job")
    [ "$(jq -r .outcome <<<"$completion")" = timed_out ] || fail "running job was not completed on stop: ${completion:-none}"
    [ "$(systemctl show -p Result --value mucki-agent.service)" = success ] || fail "stop was not graceful: $(systemctl show -p Result --value mucki-agent.service)"
    [ -z "$(ls -A /var/lib/mucki-agent/state/jobs)" ] || fail "journal record left after the graceful stop"
    systemctl start mucki-agent.service
    wait_for 10 "agent active again" agent_active
    ok "systemctl stop stops claiming, journals and completes the running job within the stop budget"
}

# expect_refused DESCRIPTION REASON: the agent must exit 78 without a restart.
expect_refused() {
    systemctl restart mucki-agent.service || true
    wait_for 15 "$1 refused" agent_failed
    [ "$(systemctl show -p ExecMainStatus --value mucki-agent.service)" = 78 ] || fail "$1: exit status $(systemctl show -p ExecMainStatus --value mucki-agent.service)"
    [ "$(systemctl show -p NRestarts --value mucki-agent.service)" = 0 ] || fail "$1: systemd restarted a refused start"
    journalctl -u mucki-agent.service --no-pager -n 20 | grep "agent cannot start" | tail -n 1 | grep -q -- "$2" ||
        fail "$1: expected reason '$2' in the journal"
    systemctl reset-failed mucki-agent.service
}

test_unsafe_files_refuse_the_start() {
    local cases=(
        "config 0644|chmod 0644 /etc/mucki-agent/config.yaml|chmod 0600 /etc/mucki-agent/config.yaml|exceed 0600"
        "secret 0644|chmod 0644 /etc/mucki-agent/secret|chmod 0600 /etc/mucki-agent/secret|exceed 0600"
        "secret of another user|chown 1000 /etc/mucki-agent/secret|chown 0 /etc/mucki-agent/secret|owned"
        "policy 0666|chmod 0666 /etc/mucki-agent/policy.yaml|chmod 0644 /etc/mucki-agent/policy.yaml|exceed 0644"
        "policy symlink|mv /etc/mucki-agent/policy.yaml /etc/mucki-agent/real.yaml && ln -s real.yaml /etc/mucki-agent/policy.yaml|rm /etc/mucki-agent/policy.yaml && mv /etc/mucki-agent/real.yaml /etc/mucki-agent/policy.yaml|symlink"
        "state 0755|chmod 0755 /var/lib/mucki-agent/state|chmod 0700 /var/lib/mucki-agent/state|use mode 0700"
        "config parent 0777|chmod 0777 /etc/mucki-agent|chmod 0755 /etc/mucki-agent|writable by group or others"
        "state parent 0777|chmod 0777 /var/lib/mucki-agent|chmod 0755 /var/lib/mucki-agent|writable by group or others"
    )
    local entry description break_it repair reason
    for entry in "${cases[@]}"; do
        IFS='|' read -r description break_it repair reason <<<"$entry"
        bash -c "$break_it"
        expect_refused "$description" "$reason"
        bash -c "$repair"
    done
    systemctl start mucki-agent.service
    wait_for 10 "agent active after the repairs" agent_active
    ok "unsafe config, secret, policy, state or parent directories refuse the start with exit 78 and no restart"
}

test_repeated_install_is_idempotent() {
    local before
    before=$(sha256sum /etc/systemd/system/mucki-agent.service /etc/systemd/system/mucki-agent.service.d/10-destinations.conf /etc/mucki-agent/config.yaml /etc/mucki-agent/policy.yaml)
    chmod 0750 /var/lib/mucki-agent/wakeup
    "$PACKAGING_DIR/install.sh" --binary "$BINARY" >/tmp/muwa-e2e-install.log 2>&1 || fail "repeated install failed: $(cat /tmp/muwa-e2e-install.log)"
    [ "$before" = "$(sha256sum /etc/systemd/system/mucki-agent.service /etc/systemd/system/mucki-agent.service.d/10-destinations.conf /etc/mucki-agent/config.yaml /etc/mucki-agent/policy.yaml)" ] ||
        fail "repeated install changed unit, drop-in, config or policy"
    [ "$(stat -c '%u:%g %a' /var/lib/mucki-agent/wakeup)" = "0:33 770" ] || fail "repeated install did not repair the wakeup directory"
    agent_active || fail "agent not active after the repeated install"
    ok "a repeated install changes nothing but repairs drifted directory permissions"
}

test_uninstall_keeps_data_and_purge_protects_the_journal() {
    "$PACKAGING_DIR/uninstall.sh" >/dev/null || fail "uninstall failed"
    [ ! -e /usr/local/bin/mucki-agent ] && [ ! -e /etc/systemd/system/mucki-agent.service ] || fail "binary or unit left after uninstall"
    [ -f /etc/mucki-agent/secret ] && [ -d /var/lib/mucki-agent/state ] || fail "uninstall removed configuration or journal"
    install -d -m 0700 /var/lib/mucki-agent/state/jobs
    touch /var/lib/mucki-agent/state/jobs/0190c3a4-5b6e-7f80-9a1b-2c3d4e5f6a7b.json
    if "$PACKAGING_DIR/uninstall.sh" --purge >/dev/null 2>&1; then
        fail "purge deleted an open journal record"
    fi
    rm -f /var/lib/mucki-agent/state/jobs/*.json
    "$PACKAGING_DIR/uninstall.sh" --purge >/dev/null || fail "purge failed"
    [ ! -e /etc/mucki-agent ] && [ ! -e /var/lib/mucki-agent/state ] || fail "purge left configuration or state"
    ok "uninstall keeps configuration and journal; purge refuses while job records are open"
}

main() {
    parse_args "$@"
    local tool
    for tool in docker jq curl nsenter; do
        command -v "$tool" >/dev/null || { echo "$tool is required" >&2; exit 2; }
    done
    trap cleanup EXIT
    cleanup
    prepare_host
    test_wakeup_contract_is_checked_before_anything_is_installed
    test_install_without_secret_does_not_start
    test_provisioned_install_starts_the_hardened_service
    test_the_sandbox_is_read_only_except_state_and_policy_paths
    test_every_host_function_works_inside_the_sandbox
    test_reload_keeps_the_previous_policy_on_error
    test_stop_finishes_the_running_job_within_the_stop_budget
    test_unsafe_files_refuse_the_start
    test_repeated_install_is_idempotent
    test_uninstall_keeps_data_and_purge_protects_the_journal
    echo "1..$PASSED"
}

main "$@"
