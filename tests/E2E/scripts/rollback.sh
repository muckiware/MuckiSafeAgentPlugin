# shellcheck shell=bash
# The controlled rollback of Task 33, played through once against the real
# stack (docs/rollout-runbook.md). Sourced by run-linux-e2e.sh after
# inject-failure.sh; rollback_check is the orchestrator step `rollback`.
#
# Flow: one successful agent run leaves the production state (read ACLs
# kept), then a backup is stalled in its first hooked phase, `muwa:agent:rollback
# start` drains it (running -> cancel_requested, the agent stops on the next
# lease heartbeat), the status gate reports no open job, the run ends
# terminal. While the switch is active a backup creates neither run nor job
# but still a snapshot. Then the repository's agent hooks are removed over
# the admin API, the switch is stopped and the plain Facility backup
# ("legacy run") is verified. Finally the hooks are restored, so the step can
# be repeated and inject_reset_all can recover an aborted rollback.

ROLLBACK_HOOKS_FILE_NAME=rollback-hooks.jsonl

rollback_hooks_file() {
    echo "$WORK_DIR/$ROLLBACK_HOOKS_FILE_NAME"
}

# rollback_console ACTION [OPTIONS...]: muwa:agent:rollback ACTION --json.
# Call it directly, never in $( ): it stores the exit code in ROLLBACK_EXIT
# (0 ready, 3 open jobs) without failing and the JSON status in a file that
# rollback_field reads.
ROLLBACK_EXIT=""
rollback_console() {
    local action=$1 file
    shift
    file=$WORK_DIR/rollback-status.json
    set +e
    console muwa:agent:rollback "$action" --json "$@" >"$file"
    ROLLBACK_EXIT=$?
    set -e
    log "muwa:agent:rollback $action exit code $ROLLBACK_EXIT: $(jq -c . "$file" 2>/dev/null || head -c 2000 "$file")"
}

# rollback_field JQ_FILTER: a field of the last rollback_console status.
rollback_field() {
    jq -c "$1" "$WORK_DIR/rollback-status.json"
}

rollback_db_active() {
    sql "SELECT active FROM muwa_agent_rollback WHERE id = 1"
}

repository_hook_count() {
    sql "SELECT COUNT(*) FROM muwa_agent_repository_hook
        WHERE facility_repository_id = UNHEX('$(cat "$WORK_DIR/repository-id")')"
}

agent_run_count() {
    sql "SELECT COUNT(*) FROM muwa_agent_backup_run"
}

agent_job_count() {
    sql "SELECT COUNT(*) FROM muwa_agent_job"
}

snapshot_count() {
    restic_in_app snapshots --json </dev/null | jq 'length'
}

# rollback_hooks_save: the repository's hook rows, one JSON payload of the
# hook API per line, kept in WORK_DIR until they are restored.
rollback_hooks_save() {
    sql "SELECT JSON_OBJECT('id', LOWER(HEX(id)), 'facilityRepositoryId', LOWER(HEX(facility_repository_id)),
            'phase', phase, 'agentHostId', LOWER(HEX(agent_host_id)), 'agentActionId', LOWER(HEX(agent_action_id)),
            'timeoutSeconds', timeout_seconds, 'failOnError', fail_on_error = 1, 'enabled', enabled = 1)
         FROM muwa_agent_repository_hook
         WHERE facility_repository_id = UNHEX('$(cat "$WORK_DIR/repository-id")')
         ORDER BY phase" >"$(rollback_hooks_file).tmp"
    mv -f "$(rollback_hooks_file).tmp" "$(rollback_hooks_file)"
}

# rollback_hooks_restore: re-creates the saved hooks (the hook API upserts by
# repository and phase, the actions were never deleted) and drops the file.
rollback_hooks_restore() {
    local file line payload
    file=$(rollback_hooks_file)
    [ -s "$file" ] || return 0
    while IFS= read -r line; do
        [ -n "$line" ] || continue
        payload=$(jq -c '{facilityRepositoryId, phase, agentHostId, agentActionId, timeoutSeconds,
            failOnError: (.failOnError == true or .failOnError == 1), enabled: (.enabled == true or .enabled == 1)}' <<<"$line")
        api POST /api/_action/muwa/agent/repository-hook "$payload" >/dev/null
    done <"$file"
    assert_eq "$(repository_hook_count)" "$(grep -c . "$file")" "restored agent hooks"
    rm -f "$file"
}

# rollback_reset: what an aborted rollback case leaves behind (called by
# inject_reset_all): the switch is stopped and saved hooks come back.
rollback_reset() {
    [ -f "$WORK_DIR/stack.env" ] || return 0
    console muwa:agent:rollback stop >/dev/null 2>&1 || true
    [ -f "$WORK_DIR/repository-id" ] || return 0
    rollback_hooks_restore || true
}

# rollback_production_state: the host state a production rollback starts
# from. One successful agent run whose effects are kept: in production the
# read ACL of the last run stays (contracts: cleanup.production.retainReadAcl),
# and the plain Facility backups below depend on it, because during the
# rollback no agent hook grants it. The E2E resets ACLs after every case, so
# this run deliberately skips scenario_reset; rollback_check resets at the end.
rollback_production_state() {
    scenario_inject "$SCENARIO_SUCCESS_CASE"
    backup_trigger
    assert_case "$SCENARIO_SUCCESS_CASE"
    scenario_assert_effects "$SCENARIO_SUCCESS_CASE"
    assert_no_open_jobs
}

no_agent_run_in_flight() {
    [ "$(sql "SELECT COUNT(*) FROM muwa_agent_backup_run WHERE status = 'running'")" = 0 ]
}

# assert_plain_facility_backup DESCRIPTION: one backup that runs without any
# agent involvement: exit 0, no new run, no new job, one new snapshot.
assert_plain_facility_backup() {
    local description=$1 runs jobs snapshots
    runs=$(agent_run_count)
    jobs=$(agent_job_count)
    snapshots=$(snapshot_count)
    backup_trigger
    assert_eq "$BACKUP_EXIT" 0 "backup exit code ($description)"
    assert_eq "$(latest_run_id)" "$(cat "$WORK_DIR/run-before-trigger")" "latest agent run ($description)"
    assert_eq "$(agent_run_count)" "$runs" "agent runs ($description)"
    assert_eq "$(agent_job_count)" "$jobs" "agent jobs ($description)"
    assert_eq "$(snapshot_count)" "$((snapshots + 1))" "snapshots ($description)"
}

# Steps 1-3: a running job is drained by the rollback and the run ends
# terminal; the agent journal shows the acknowledged stop.
rollback_drain_running_backup() {
    local phase mark
    phase=$(first_hook 1)
    scenario_stall on
    backup_trigger_async
    wait_for 120 "$phase job running" job_status_is "$phase" running
    mark=$(journal_mark)
    rollback_console start
    assert_eq "$ROLLBACK_EXIT" 3 "rollback start exit code with a running job"
    assert_eq "$(rollback_field '.active')" true "rollback active after start"
    assert_eq "$(rollback_field '.openJobs.cancel_requested')" 1 "cancel_requested jobs after start"
    assert_eq "$(rollback_field '.readyForHookRemoval')" false "ready for hook removal with a running job"
    assert_eq "$(rollback_db_active)" 1 "rollback switch in the database"
    # The agent acknowledges stopRequested on its next lease heartbeat (10 s);
    # only then the stalled host effect is released, so the stop and not a
    # late success decides the job.
    wait_for 60 "agent stops the $phase job" assert_journal_any "$mark" "agent stop" "stopping job execution"
    assert_journal_any "$mark" "stop reason" "stop requested by the control plane"
    scenario_stall off
    rollback_console status --wait=180
    assert_eq "$ROLLBACK_EXIT" 0 "rollback status --wait exit code"
    assert_eq "$(rollback_field '.readyForHookRemoval')" true "ready for hook removal"
    assert_eq "$(rollback_field '.openJobs')" '{"queued":0,"claimed":0,"running":0,"cancel_requested":0}' "open jobs"
    backup_wait
    assert_new_run
    run_save "rollback-drained" >/dev/null
    case $(run_json | jq -r '.status') in
        failed|unknown) ;;
        *) log "assertion failed: drained run is $(run_json | jq -r '.status')"; return 1 ;;
    esac
    case $(job_status "$phase") in
        timed_out|unknown) ;;
        *) log "assertion failed: drained $phase job ended $(job_status "$phase")"; return 1 ;;
    esac
    log "drained $phase job: $(job_of "$phase" | jq -c '{status, resultCode, resultSummary}'); run: $(run_json | jq -c '{status, primaryErrorCode}')"
    assert_eq "$(run_json | jq -r '[.jobs[] | select(.status == "queued" or .status == "claimed" or .status == "running" or .status == "cancel_requested")] | length')" 0 "open jobs of the drained run"
    no_agent_run_in_flight || { log "assertion failed: an agent run is still running"; return 1; }
}

rollback_check() {
    local hook_id
    [ "$(rollback_db_active)" = 0 ] || { log "rollback switch is already active"; return 1; }
    [ "$(repository_hook_count)" -gt 0 ] || { log "the repository has no agent hooks"; return 1; }
    rollback_console status
    assert_eq "$ROLLBACK_EXIT" 0 "no open job before the rollback"
    rollback_production_state
    rollback_drain_running_backup
    # Step 4: switch active, hooks still assigned.
    assert_plain_facility_backup "rollback active, hooks assigned"
    # Step 5: hook removal, switch off, the plain Facility backup.
    rollback_hooks_save
    for hook_id in $(jq -r '.id' "$(rollback_hooks_file)"); do
        assert_eq "$(api DELETE "/api/_action/muwa/agent/repository-hook/$hook_id" | jq -r '.deleted')" true "hook $hook_id deleted"
    done
    assert_eq "$(repository_hook_count)" 0 "agent hooks after removal"
    rollback_console stop
    assert_eq "$ROLLBACK_EXIT" 0 "rollback stop exit code"
    assert_eq "$(rollback_field '.active')" false "rollback active after stop"
    assert_eq "$(rollback_db_active)" 0 "rollback switch in the database after stop"
    assert_plain_facility_backup "rollback stopped, hooks removed"
    # Step 6: back to the start state of the other steps.
    rollback_hooks_restore
    assert_no_open_jobs
    rm -f "$WORK_DIR/rollback-status.json"
    scenario_reset "$SCENARIO_SUCCESS_CASE"
}
