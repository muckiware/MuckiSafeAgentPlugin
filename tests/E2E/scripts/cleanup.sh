# shellcheck shell=bash
# Artifact collection and teardown of the Linux service E2E. Sourced by
# run-linux-e2e.sh after the other step scripts. Every function tolerates a
# partially set up host, so the EXIT trap can always run it.

# collect_artifacts: container and agent logs, run/job states and versions
# for the CI artifact (never secrets: stack.env, the agent secret and the
# keyring stay on the runner).
collect_artifacts() {
    mkdir -p "$OUT_DIR/logs"
    journalctl -u "$AGENT_UNIT" --no-pager >"$OUT_DIR/logs/mucki-agent.journal" 2>&1 || true
    if [ -f "$WORK_DIR/stack.env" ]; then
        stack ps -a >"$OUT_DIR/logs/stack-ps.txt" 2>&1 || true
        stack logs --no-color --timestamps >"$OUT_DIR/logs/stack.log" 2>&1 || true
        sql "SELECT JSON_OBJECT('id', LOWER(HEX(id)), 'repository', LOWER(HEX(facility_repository_id)),
                'status', status, 'primaryErrorCode', primary_error_code, 'startedAt', started_at, 'finishedAt', finished_at)
             FROM muwa_agent_backup_run ORDER BY created_at" >"$OUT_DIR/logs/runs.jsonl" 2>/dev/null || true
        sql "SELECT JSON_OBJECT('id', LOWER(HEX(id)), 'run', LOWER(HEX(backup_run_id)), 'phase', phase, 'status', status,
                'attempt', attempt, 'leaseVersion', lease_version, 'resultCode', result_code, 'resultSummary', result_summary)
             FROM muwa_agent_job ORDER BY created_at" >"$OUT_DIR/logs/jobs.jsonl" 2>/dev/null || true
        stack exec -T app cat /var/www/html/.muckisafe-versions >"$OUT_DIR/logs/muckisafe-versions.txt" 2>/dev/null || true
    fi
    /usr/local/bin/mucki-agent --version >"$OUT_DIR/logs/agent-version.txt" 2>&1 || true
    scenario_collect_logs "$OUT_DIR/logs" 2>/dev/null || true
}

# Optional scenario hook; scenarios override it for their own containers.
scenario_collect_logs() {
    return 0
}

# remove_agent: only an installation this E2E made (marker in /etc/mucki-agent);
# a real agent of the host is never touched.
remove_agent() {
    [ -e /etc/mucki-agent/.muwa-e2e ] || return 0
    if [ -x /usr/local/bin/mucki-agent ] || [ -f "/etc/systemd/system/$AGENT_UNIT" ]; then
        systemctl stop "$AGENT_UNIT" 2>/dev/null || true
        # Without --purge: the journal may legitimately hold records after
        # fault injection; the work tree below is removed explicitly.
        "${AGENT_PACKAGE_DIR:-$REPO_ROOT/agent/packaging}/uninstall.sh" >/dev/null 2>&1 || true
    fi
    rm -rf /etc/mucki-agent /var/lib/mucki-agent
}

remove_trust() {
    rm -f /usr/local/share/ca-certificates/muwa-e2e.crt
    if command -v update-ca-certificates >/dev/null; then
        update-ca-certificates --fresh >/dev/null 2>&1 || true
    fi
    sed -i "/ $HTTPS_HOST\$/d" /etc/hosts 2>/dev/null || true
}

# teardown: leaves the host as before the run (disposable runners anyway).
teardown() {
    set +e
    inject_reset_all >/dev/null 2>&1
    scenario_down >/dev/null 2>&1
    [ -f "$WORK_DIR/stack.env" ] && stack down --volumes --remove-orphans >/dev/null 2>&1
    remove_agent
    remove_trust
    # Only a work directory this E2E created (marker written by stack_prepare).
    [ -e "$WORK_DIR/.muwa-e2e" ] && rm -rf "$WORK_DIR"
    set -e
}
