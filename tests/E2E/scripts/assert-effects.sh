# shellcheck shell=bash
# Generic assertions of the Linux service E2E. Sourced by run-linux-e2e.sh
# after lib.sh, setup.sh and trigger-backup.sh. Service-specific host
# effects (dump present, unit active, HTTP health, retention, ACLs) belong to
# the scenario's scenario_assert_effects.

assert_eq() {
    [ "$1" = "$2" ] || { log "assertion failed: $3: expected '$2', got '$1'"; return 1; }
}

assert_prefix() {
    case $1 in
        "$2"*) return 0 ;;
    esac
    log "assertion failed: $3: expected prefix '$2', got '$1'"
    return 1
}

assert_contains() {
    grep -qF -- "$2" <<<"$1" || { log "assertion failed: $3: '$2' not found"; return 1; }
}

assert_not_contains() {
    if grep -qF -- "$2" <<<"$1"; then
        log "assertion failed: $3: '$2' must not occur"
        return 1
    fi
}

# assert_phase PHASE EXPECTATION_JSON: the phase's job matches outcome,
# resultCode and summaryPrefix of the fixture.
assert_phase() {
    local phase=$1 expected=$2 job
    job=$(job_of "$phase")
    [ -n "$job" ] || { log "assertion failed: no $phase job in the run"; return 1; }
    assert_eq "$(jq -r '.status' <<<"$job")" "$(jq -r '.outcome' <<<"$expected")" "$phase status"
    if jq -e 'has("resultCode") and .resultCode != null' <<<"$expected" >/dev/null; then
        assert_eq "$(jq -r '.resultCode // ""' <<<"$job")" "$(jq -r '.resultCode' <<<"$expected")" "$phase result code"
    fi
    if jq -e 'has("summaryPrefix") and .summaryPrefix != null' <<<"$expected" >/dev/null; then
        assert_prefix "$(jq -r '.resultSummary // ""' <<<"$job")" "$(jq -r '.summaryPrefix' <<<"$expected")" "$phase summary"
    fi
}

# assert_core EXPECTED: readable|succeeded -> core ran successfully,
# failed -> Facility error, skipped -> a fail-on-error pre hook stopped it.
assert_core() {
    local expected=$1 actual
    actual=$(core_outcome)
    case $expected in
        readable|succeeded) assert_eq "$actual" succeeded "core outcome" ;;
        failed) assert_eq "$actual" failed "core outcome" ;;
        skipped) assert_eq "$actual" skipped "core outcome" ;;
        *) log "unknown core expectation '$expected'"; return 1 ;;
    esac
}

# assert_new_run: the trigger created a run (not the one of an earlier case).
assert_new_run() {
    local before now
    before=$(cat "$WORK_DIR/run-before-trigger" 2>/dev/null || true)
    now=$(latest_run_id)
    [ -n "$now" ] && [ "$now" != "$before" ] ||
        { log "assertion failed: the backup created no new agent run (latest: '${now:-none}')"; return 1; }
}

assert_run_terminal() {
    local status
    status=$(run_json | jq -r '.status // ""')
    case $status in
        succeeded|failed|unknown) ;;
        *) log "assertion failed: run status is '$status', expected a terminal status"; return 1 ;;
    esac
    # The console exit code agrees with the run: only a fully successful run
    # may end the backup command with 0.
    if [ "$status" = succeeded ]; then
        assert_eq "$BACKUP_EXIT" 0 "backup exit code of a successful run"
    else
        [ "$BACKUP_EXIT" != 0 ] || [ "$(core_outcome)" = succeeded ] ||
            { log "assertion failed: backup exited 0 although the core did not succeed"; return 1; }
    fi
}

# assert_case CASE: everything the fixture states generically about a case.
assert_case() {
    local name=$1 expectations phase
    assert_new_run
    run_save "$name" >/dev/null
    assert_run_terminal
    expectations=$(expect_phases "$name")
    for phase in pre post finally; do
        jq -e --arg p "$phase" 'has($p)' <<<"$expectations" >/dev/null || continue
        if jq -e --arg p "$phase" '.[$p] == null' <<<"$expectations" >/dev/null; then
            # "phase": null in the fixture: that phase must not have run.
            [ -z "$(job_of "$phase")" ] || { log "assertion failed: a $phase job ran although none is expected"; return 1; }
            continue
        fi
        assert_phase "$phase" "$(jq -c --arg p "$phase" '.[$p]' <<<"$expectations")"
    done
    if jq -e 'has("core")' <<<"$expectations" >/dev/null; then
        assert_core "$(jq -r '.core' <<<"$expectations")"
    fi
}

# assert_no_open_jobs: no job of the repository is left non-terminal.
assert_no_open_jobs() {
    local repository_id open
    repository_id=$(cat "$WORK_DIR/repository-id")
    open=$(sql "SELECT COUNT(*) FROM muwa_agent_job WHERE facility_repository_id = UNHEX('$repository_id')
        AND status IN ('queued','claimed','running','cancel_requested')")
    assert_eq "$open" 0 "open jobs"
}
