# shellcheck shell=bash
# Generic fault injection and the resilience cases every scenario runs with
# its success case: agent offline, API offline, lost and duplicated wakeups,
# agent crash, lease expiry with an old worker, completion retry and a
# Facility core failure. Sourced by run-linux-e2e.sh after lib.sh, setup.sh,
# trigger-backup.sh and assert-effects.sh.
#
# Deterministic timing comes from the scenario: scenario_stall on makes the
# first host effect of the first hooked phase hang until scenario_stall off.
#
# Async backups: call backup_trigger_async directly (never in $( )) and
# collect them with backup_wait — see trigger-backup.sh.

# first_hook FIELD: field of the first scenario_hooks line
# (1 phase, 2 action file, 3 idempotency class, 4 timeout, 5 fail-on-error).
first_hook() {
    scenario_hooks | awk -v field="$1" 'NF { print $field; exit }'
}

# dashed_uuid HEX32: the UUID form of wakeup file names.
dashed_uuid() {
    printf '%s-%s-%s-%s-%s\n' "${1:0:8}" "${1:8:4}" "${1:12:4}" "${1:16:4}" "${1:20:12}"
}

# run_wakeup_files: the wakeup file names of the latest run's jobs.
run_wakeup_files() {
    local id
    for id in $(run_json | jq -r '.jobs[].id'); do
        echo "$WAKEUP_HOST_DIR/$(dashed_uuid "$id").ready"
    done
}

# --- injectors ----------------------------------------------------------------

agent_installed() {
    [ -f "/etc/systemd/system/$AGENT_UNIT" ]
}

agent_stop() {
    systemctl stop "$AGENT_UNIT"
}

agent_start() {
    agent_mark_restart
    systemctl start "$AGENT_UNIT"
    wait_for 60 "agent host active" agent_active
}

# api_block / api_unblock: the agent's HTTPS connections to the proxy are
# reset. Matches the original destination port, because published ports are
# DNATed before the filter table sees them.
api_block() {
    iptables -I OUTPUT -p tcp -m conntrack --ctorigdstport "$HTTPS_PORT" -j REJECT --reject-with tcp-reset
}

api_unblock() {
    while iptables -D OUTPUT -p tcp -m conntrack --ctorigdstport "$HTTPS_PORT" -j REJECT --reject-with tcp-reset 2>/dev/null; do :; done
}

# wakeup_block / wakeup_unblock: www-data can no longer create wakeup files
# (lost wakeups); polling must still find every job.
wakeup_block() {
    chmod 0700 "$WAKEUP_HOST_DIR"
}

wakeup_unblock() {
    chmod 0770 "$WAKEUP_HOST_DIR"
}

# wakeup_duplicate_for SECONDS: adds a fixed set of wakeups for jobs that do
# not exist, then re-announces the running backup's own job wakeups again and
# again (duplicated wakeups).
wakeup_duplicate_for() {
    local deadline=$((SECONDS + $1)) file _
    for _ in $(seq 1 20); do
        install -o 33 -g 33 -m 0640 /dev/null "$WAKEUP_HOST_DIR/$(cat /proc/sys/kernel/random/uuid).ready"
    done
    while [ "$SECONDS" -lt "$deadline" ]; do
        for file in $(run_wakeup_files); do
            install -o 33 -g 33 -m 0640 /dev/null "$file.dup" && mv -f "$file.dup" "$file"
        done
        sleep 0.5
    done
}

agent_freeze() {
    systemctl kill --kill-whom=main -s SIGSTOP "$AGENT_UNIT"
}

agent_thaw() {
    systemctl kill --kill-whom=main -s SIGCONT "$AGENT_UNIT"
}

# core_break / core_repair: the restic repository becomes unwritable for
# www-data, so the Facility core fails after a successful pre phase.
core_repository_dir() {
    echo "$BACKUP_REPOS_HOST_DIR/$SCENARIO_NAME-$(cat "$WORK_DIR/repository-id")"
}

core_break() {
    [ -d "$(core_repository_dir)" ] || die "restic repository $(core_repository_dir) does not exist"
    chmod -R a-w "$(core_repository_dir)"
}

core_repair() {
    chmod -R u+w,g+w "$(core_repository_dir)"
}

no_running_run() {
    [ "$(sql "SELECT COUNT(*) FROM muwa_agent_backup_run WHERE status = 'running'")" = 0 ]
}

# Everything an aborted case may leave behind, including a backup that is
# still running in the app container.
inject_reset_all() {
    api_unblock
    [ -d "$WAKEUP_HOST_DIR" ] && wakeup_unblock
    agent_installed || return 0
    agent_thaw 2>/dev/null || true
    scenario_stall off || true
    systemctl is-active --quiet "$AGENT_UNIT" || agent_start
    [ -f "$WORK_DIR/repository-id" ] && [ -d "$(core_repository_dir)" ] && core_repair
    if [ -f "$WORK_DIR/stack.env" ]; then
        stack exec -T app pkill -f 'muckiware:backup:create' </dev/null >/dev/null 2>&1 || true
        wait_for 300 "no running agent run" no_running_run || true
    fi
}

# --- helpers --------------------------------------------------------------------

job_status_is() {
    [ "$(job_status "$1")" = "$2" ]
}

job_decided() {
    case $(job_status "$1") in
        unknown|timed_out|failed|succeeded) return 0 ;;
    esac
    return 1
}

expect_success_run() {
    assert_new_run
    assert_run_terminal
    assert_eq "$(run_json | jq -r '.status')" succeeded "run status"
    scenario_assert_effects "$SCENARIO_SUCCESS_CASE"
    scenario_reset "$SCENARIO_SUCCESS_CASE"
}

# assert_journal_any MARK DESCRIPTION TEXT...: one of the texts is logged.
assert_journal_any() {
    local mark=$1 description=$2 journal text
    shift 2
    journal=$(agent_journal_since "$mark")
    for text in "$@"; do
        grep -qF -- "$text" <<<"$journal" && return 0
    done
    log "assertion failed: $description: none of '$*' logged"
    return 1
}

# --- resilience cases -------------------------------------------------------------

# The agent is offline when the job is queued and comes back within the phase
# budget: the job is claimed late and the backup succeeds.
resilience_agent_offline() {
    local phase
    phase=$(first_hook 1)
    agent_stop
    backup_trigger_async
    wait_for 120 "$phase job queued" job_status_is "$phase" queued
    sleep 5
    agent_start
    backup_wait
    expect_success_run
}

# The agent stays offline past the phase deadline: the never-started job
# times out and is never executed afterwards.
resilience_agent_offline_past_deadline() {
    local phase mark
    phase=$(first_hook 1)
    agent_stop
    backup_trigger
    assert_new_run
    assert_eq "$(job_status "$phase")" timed_out "$phase job without agent"
    mark=$(journal_mark)
    agent_start
    sleep 15
    assert_eq "$(job_status "$phase")" timed_out "$phase job after the agent returned"
    assert_not_contains "$(agent_journal_since "$mark")" "job completed" "late execution"
    scenario_reset "$SCENARIO_SUCCESS_CASE"
}

# The API is unreachable for the agent while the job is queued: claims fail,
# polling retries, the backup succeeds once the API is back.
resilience_api_offline() {
    local mark
    mark=$(journal_mark)
    api_block
    backup_trigger_async
    sleep 25
    api_unblock
    backup_wait
    assert_journal_any "$mark" "claim failure logged" "claim failed, waiting for the next poll"
    expect_success_run
}

# Lost wakeups: no wakeup file of this run can be written; the poll still
# claims the job.
resilience_wakeup_lost() {
    local file
    wakeup_block
    backup_trigger
    wakeup_unblock
    for file in $(run_wakeup_files); do
        [ ! -e "$file" ] || { log "assertion failed: wakeup $file was written although blocked"; return 1; }
    done
    expect_success_run
}

# Duplicated and foreign wakeups: every job still runs exactly once.
resilience_wakeup_duplicate() {
    local duplicator
    backup_trigger_async
    wakeup_duplicate_for 30 &
    duplicator=$!
    backup_wait
    wait "$duplicator" || true
    assert_eq "$(run_json | jq -r '[.jobs[] | select(.attempt != 1) | .phase] | join(",")')" "" "jobs with more than one attempt"
    expect_success_run
}

# Agent crash (SIGKILL, systemd restarts it) while the first job runs: a safe
# job is re-executed and succeeds, a non-retryable one ends unknown instead of
# being repeated.
resilience_agent_restart() {
    local phase class mark
    phase=$(first_hook 1)
    class=$(first_hook 3)
    scenario_stall on
    backup_trigger_async
    wait_for 120 "$phase job running" job_status_is "$phase" running
    mark=$(journal_mark)
    agent_mark_restart
    systemctl kill --kill-whom=main -s SIGKILL "$AGENT_UNIT"
    # Restart=on-failure, RestartSec=10s.
    wait_for 90 "agent restarted by systemd" agent_active
    scenario_stall off
    backup_wait
    assert_new_run
    run_save "resilience-agent-restart" >/dev/null
    if [ "$class" = safe ]; then
        assert_eq "$(job_status "$phase")" succeeded "safe $phase job after the crash"
        assert_journal_any "$mark" "safe re-execution" "re-executing safe job after restart"
    else
        assert_eq "$(job_status "$phase")" unknown "non-retryable $phase job after the crash"
        case $(run_json | jq -r '.status') in
            failed|unknown) ;;
            *) log "assertion failed: run after an unknown $phase job is $(run_json | jq -r '.status')"; return 1 ;;
        esac
    fi
    scenario_reset "$SCENARIO_SUCCESS_CASE"
}

# Lease expiry with an old worker: the agent is frozen while its job runs
# until the control plane has decided the job at the phase deadline. The
# running job is never requeued, and the thawed old worker cannot overturn
# the decision.
resilience_lease_expiry() {
    local phase timeout mark
    phase=$(first_hook 1)
    timeout=$(first_hook 4)
    scenario_stall on
    backup_trigger_async
    wait_for 120 "$phase job running" job_status_is "$phase" running
    mark=$(journal_mark)
    agent_freeze
    wait_for $((timeout + 180)) "control plane decides the frozen $phase job" job_decided "$phase"
    scenario_stall off
    agent_thaw
    backup_wait
    assert_new_run
    run_save "resilience-lease-expiry" >/dev/null
    case $(job_status "$phase") in
        unknown|timed_out) ;;
        *) log "assertion failed: frozen $phase job ended $(job_status "$phase")"; return 1 ;;
    esac
    assert_eq "$(run_json | jq -r --arg p "$phase" '[.jobs[] | select(.phase == $p)] | length')" 1 "$phase job never requeued"
    assert_eq "$(job_of "$phase" | jq -r '.attempt')" 1 "$phase attempts"
    wait_for 60 "old worker reports and is fenced" assert_journal_any "$mark" "old worker fenced" \
        "completion rejected, the control plane has decided the job" "phase deadline passed without accepted completion"
    scenario_reset "$SCENARIO_SUCCESS_CASE"
}

# Completion retry: the API disappears while the job runs and comes back
# within the lease; the stored result is delivered and accepted once.
resilience_completion_retry() {
    local phase mark
    phase=$(first_hook 1)
    scenario_stall on
    backup_trigger_async
    wait_for 120 "$phase job running" job_status_is "$phase" running
    mark=$(journal_mark)
    api_block
    scenario_stall off
    sleep 15
    api_unblock
    backup_wait
    assert_journal_any "$mark" "completion retry" "completion failed, retrying"
    assert_eq "$(job_status "$phase")" succeeded "$phase job after completion retry"
    assert_eq "$(job_of "$phase" | jq -r '.attempt')" 1 "$phase attempts"
    expect_success_run
}

# Facility core failure: pre succeeded, the core fails, post is skipped and
# finally (if hooked) still runs; the run records the core as primary error.
resilience_core_failure() {
    core_break
    backup_trigger
    core_repair
    assert_new_run
    run_save "resilience-core-failure" >/dev/null
    assert_eq "$(core_outcome)" failed "core outcome"
    assert_eq "$(run_json | jq -r '.status')" failed "run status"
    assert_eq "$(job_status "$(first_hook 1)")" succeeded "first phase before the core"
    [ -z "$(job_of post)" ] || { log "assertion failed: post ran after a core failure"; return 1; }
    if scenario_hooks | awk '$1 == "finally" { found = 1 } END { exit !found }'; then
        assert_eq "$(job_status finally)" succeeded "finally after a core failure"
    fi
    scenario_reset "$SCENARIO_SUCCESS_CASE"
}

# The resilience cases in execution order.
resilience_cases() {
    cat <<'EOF'
agent offline then back	resilience_agent_offline
agent offline past the phase deadline	resilience_agent_offline_past_deadline
API offline	resilience_api_offline
lost wakeup	resilience_wakeup_lost
duplicated wakeup	resilience_wakeup_duplicate
agent crash	resilience_agent_restart
lease expiry and old worker	resilience_lease_expiry
completion retry	resilience_completion_retry
core failure	resilience_core_failure
EOF
}
