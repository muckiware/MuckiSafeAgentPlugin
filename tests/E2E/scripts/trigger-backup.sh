# shellcheck shell=bash
# Triggers the real Facility backup path and reads the agent run back.
# Sourced by run-linux-e2e.sh after lib.sh and setup.sh.
#
# The backup runs exactly like a scheduled or manual one: bin/console
# muckiware:backup:create in the app container as www-data. Facility holds
# its global lock, dispatches Before/After/Finished and the AgentPlugin
# subscribers create and await the agent jobs.

BACKUP_EXIT=""
BACKUP_PID=""

# latest_run_id: id of the repository's newest agent run ("" if none).
latest_run_id() {
    sql "SELECT LOWER(HEX(id)) FROM muwa_agent_backup_run
        WHERE facility_repository_id = UNHEX('$(cat "$WORK_DIR/repository-id")')
        ORDER BY created_at DESC LIMIT 1"
}

# remember_run: the run before a trigger; assert_new_run compares with it, so
# an assertion never checks the run of an earlier case.
remember_run() {
    latest_run_id >"$WORK_DIR/run-before-trigger"
}

# backup_trigger: runs one backup and stores its exit code in BACKUP_EXIT.
# Never fails itself: a failing backup is an expected outcome of many cases.
backup_trigger() {
    local repository_id
    repository_id=$(cat "$WORK_DIR/repository-id")
    remember_run
    set +e
    console muckiware:backup:create "$repository_id"
    BACKUP_EXIT=$?
    set -e
    log "backup exit code: $BACKUP_EXIT"
}

# backup_trigger_async: starts a backup in the background and sets
# BACKUP_PID. Call it directly, never in $( ): the process must be a child
# of this shell, otherwise `wait` cannot collect it.
backup_trigger_async() {
    local repository_id
    repository_id=$(cat "$WORK_DIR/repository-id")
    remember_run
    console muckiware:backup:create "$repository_id" >"$OUT_DIR/async-backup.log" 2>&1 &
    BACKUP_PID=$!
}

# backup_wait: waits for the async backup and stores its exit code.
backup_wait() {
    [ -n "$BACKUP_PID" ] || die "no async backup running"
    set +e
    wait "$BACKUP_PID"
    BACKUP_EXIT=$?
    set -e
    BACKUP_PID=""
    [ "$BACKUP_EXIT" -ne 127 ] || die "async backup was not a child of this shell"
    log "backup exit code: $BACKUP_EXIT"
}

# run_json: the latest agent run of the repository with its jobs.
run_json() {
    local repository_id
    repository_id=$(cat "$WORK_DIR/repository-id")
    sql_json "SELECT JSON_OBJECT(
        'id', LOWER(HEX(r.id)), 'status', r.status,
        'primaryErrorCode', r.primary_error_code, 'primaryErrorSummary', r.primary_error_summary,
        'jobs', COALESCE((SELECT JSON_ARRAYAGG(JSON_OBJECT(
            'id', LOWER(HEX(j.id)), 'phase', j.phase, 'status', j.status,
            'resultCode', j.result_code, 'resultSummary', j.result_summary,
            'attempt', j.attempt, 'leaseVersion', j.lease_version,
            'claimedAt', j.claimed_at, 'finishedAt', j.finished_at, 'createdAt', j.created_at))
          FROM muwa_agent_job j WHERE j.backup_run_id = r.id), JSON_ARRAY()))
      FROM muwa_agent_backup_run r
      WHERE r.facility_repository_id = UNHEX('$repository_id')
      ORDER BY r.created_at DESC LIMIT 1"
}

# run_save NAME: stores the latest run as an artifact and prints it.
run_save() {
    mkdir -p "$OUT_DIR/runs"
    run_json | jq . | tee "$OUT_DIR/runs/$(printf '%s' "$1" | tr -c 'A-Za-z0-9_.-' '_').json"
}

# job_of PHASE: the job object of a phase in the latest run.
job_of() {
    run_json | jq -c --arg phase "$1" '.jobs[] | select(.phase == $phase)'
}

# job_status PHASE: status of the phase's job ("" while it does not exist).
job_status() {
    job_of "$1" | jq -r '.status // empty'
}

# core_outcome: Facility core result as recorded by the run:
# skipped (pre hook aborted the backup), failed (Facility error), succeeded
# (no error, or only a later post/finally hook failed) or "unexpected:CODE".
core_outcome() {
    local code
    code=$(run_json | jq -r '.primaryErrorCode // ""')
    case $code in
        pre_hook_failed) echo skipped ;;
        backup_failed) echo failed ;;
        ''|post_hook_failed|finally_hook_failed) echo succeeded ;;
        *) echo "unexpected:$code" ;;
    esac
}
