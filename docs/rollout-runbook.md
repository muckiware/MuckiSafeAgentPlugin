# Staged production rollout and controlled rollback

Runbook for Task 33 of `docs/implementation-plan.md`: how the MuckiSafe host
agent is taken into production step by step, and how it is taken out again
without losing a job or running a host action twice. Every stage has
acceptance criteria that must be **checked and recorded** (command output,
SQL readback, timestamps) before the next stage starts; Gate F requires the
complete protocol.

Related documents:

- installation of the agent on the host:
  `MuckiSafe/docs/host-agent/README.md` (repository `muckiware/MuckiSafe`);
- the legacy hooks and their host-side `systemd.path` kits:
  `MuckiSafe/docs/bitwarden-backup-prep/README.md`,
  `MuckiSafe/docs/jellyfin-backup-prep/README.md`;
- service contracts (start state, expected effects, cleanup):
  `docs/reference-services.md` and `tests/E2E/contracts/<service>.json`;
- design: `docs/design.md`, section 18 *Rollout*.

## Conventions

The commands assume the MuckiSafe Docker stack (`app`, `scheduler`, `db`) and
the agent on the same Linux host, as described in the host documentation.
Run them from the stack directory. Two shell helpers keep the commands short;
both are the same calls the Linux E2E uses (`tests/E2E/scripts/lib.sh`):

```bash
cd /path/to/muckisafe-stack

# bin/console in the app container as www-data (never as root, see the
# MuckiSafe README on file ownership).
console() { docker compose exec -T -u www-data app bin/console "$@" </dev/null; }

# Read-only SQL readback: reads one query from stdin.
sql() { docker compose exec -T db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -N -B --raw -u root "$MYSQL_DATABASE"'; }
```

All SQL in this runbook is `SELECT` only; in production prefer a dedicated
read-only database user over the root account the helper above uses. Never
change agent tables by hand:
job and run transitions are fenced by triggers and lease versions, and only
the services of the plugin keep them consistent. On a plain Shopware
installation use `bin/console` and any MySQL client with read access instead.

IDs: the database stores binary UUIDs. The SQL below prints them as 32
lowercase hex characters (the format of the admin API); the agent journal
logs job IDs in the dashed wire format (`JobClaimer` → `wireUuid`), see
[A.6](#a6-jobs-of-a-run).

Logs:

| Source | Where | Notes |
|---|---|---|
| Agent | `journalctl -u mucki-agent` | `slog` text format, e.g. `level=WARN msg="host heartbeat failed" error=...` |
| Control plane | `var/log/prod-YYYY-MM-DD.log` in the app container | Shopware production Monolog (`fingers_crossed`, level `error`): authentication rejections are logged as errors; `warning`/`info` lines (e.g. `Agent rollback started.`) only appear when the same request also logs an error |
| Legacy kits | `journalctl -u muckisafe-bitwarden-prep.service`, `journalctl -u muckisafe-jellyfin.service` | host side of the legacy hooks |

**Deploy and migrate together.** The claim and job-creation SQL read the
rollback switch table `muwa_agent_rollback` (migration `1795478400`). Run the
plugin migrations immediately with every deployment of a new plugin version
(`bin/console plugin:update MuckiSafeAgentPlugin` or, for an unchanged
version, `bin/console database:migrate MuckiSafeAgentPlugin --all`). Until
then claims fail with HTTP 500 and backups of repositories with agent hooks
fail closed; repositories without hooks are not affected.

**Audit.** `muwa_agent_rollback.started_at`/`stopped_at` ([A.2](#a2-rollback-switch-and-open-jobs))
are the authoritative record of a rollback; the log lines `Agent rollback
started.`/`stopped.` are warnings and usually filtered by the production log
level. Note who ran each step in the change ticket.

## Stage 1 — install and provision the agent, no hooks

Follow `MuckiSafe/docs/host-agent/README.md` sections 1–6: verified release,
`install.sh`, **Provision agent** in *Settings → Extensions → Mucki Agent*
(tab *Agents*), secret file, `config.yaml`, policy, `mucki-agent config
validate`, second `install.sh` run. **Do not assign any repository hook** and
do not change any Facility repository yet.

Acceptance (record all outputs):

1. The unit runs and the agent started with the expected version:

   ```bash
   systemctl is-active mucki-agent                       # active
   journalctl -u mucki-agent -b | grep 'msg="agent started"'
   ```

   The start line (`internal/daemon/daemon.go`) shows `version=`,
   `insecureTransport=false` for an `https://` URL and the `capabilities`
   of the policy.
2. The host row is enabled, not revoked and seen
   ([A.1](#a1-agent-hosts-and-heartbeat-age)); the administration shows the
   agent as *Active*.
3. No hook exists yet: [A.4](#a4-repository-hooks) returns no row.
4. The rollback switch is off: `console muwa:agent:rollback status` prints
   `Rollback active: no` ([A.2](#a2-rollback-switch-and-open-jobs)).

## Stage 2 — observe heartbeats for at least 24 hours

Record the start time of the window, then let the agent idle for at least
24 hours without hooks.

```bash
START=$(date -u '+%Y-%m-%d %H:%M:%S UTC'); echo "$START"
systemctl show -p NRestarts -p ExecMainStartTimestamp mucki-agent
```

### Observation loop

Run this in `tmux`/`screen` for the whole window. Every minute it records
the heartbeat age of each agent and the clock offsets of the two server
clocks against the agent host:

```bash
while :; do
  host=$(date -u +%s)
  php=$(docker compose exec -T app php -r 'echo time();')
  db=$(sql <<<'SELECT UNIX_TIMESTAMP()')
  ages=$(sql <<<"SELECT GROUP_CONCAT(CONCAT(name, '=', COALESCE(TIMESTAMPDIFF(SECOND, last_seen_at, UTC_TIMESTAMP(3)), -1)) SEPARATOR ' ') FROM muwa_agent_host WHERE enabled = 1 AND revoked_at IS NULL")
  echo "$(date -u +%FT%TZ) php_offset=$((php - host)) db_offset=$((db - host)) $ages"
  sleep 60
done >> rollout-observation.log
```

Evaluation after the window (must print nothing):

```bash
awk '{ for (i = 2; i <= NF; i++) { split($i, kv, "="); v = kv[2] + 0
       if (kv[1] ~ /_offset$/) { if (v > 60 || v < -60) print "CLOCK " $0 }
       else if (v < 0 || v > 30) print "GAP " $0 } }' rollout-observation.log
```

### Acceptance criteria

**Heartbeats without gaps.** The agent sends a heartbeat immediately and then
every `agent.heartbeatInterval` (default and example config: 10 s;
`internal/agent/heartbeat.go`, `internal/config/config.go`). Each accepted
heartbeat overwrites `muwa_agent_host.last_seen_at` with the server time
(`src/Service/Heartbeat/HostHeartbeat.php`); the column keeps only the latest
value, so gaps are found by sampling. With the default interval every sample
must be `0 ≤ age ≤ 30` seconds (three missed intervals; scale the threshold
with a different `heartbeatInterval`). `-1` means the agent was never seen.
Cross-check the agent side — no failed heartbeat in the window:

```bash
journalctl -u mucki-agent --since "$START" | grep -c 'msg="host heartbeat failed"'   # 0
```

**No authentication or replay errors.** The control plane rejects a request
before any controller runs (`src/Subscriber/AgentAuthenticationSubscriber.php`)
and logs the exception message as an error. Messages from
`src/Service/Auth/AgentAuthenticator.php`, `ReplayGuard.php` and
`AgentAuthException::unknownKey()`:

```bash
docker compose exec -T app sh -c 'grep -hE "No agent secret can be resolved for this key\.|Agent key is revoked or disabled\.|Signature headers are missing or malformed\.|Request timestamp is outside the allowed window\.|Request signature is invalid\.|Request nonce was already used\." var/log/prod-*.log' | wc -l   # 0
```

On the agent, signed rejections appear as `agent api: HTTP 401
MUWA_AGENT_<CODE>: ...` (`internal/api/client_errors.go`), the unsigned 401 of
an unknown key as `key id not accepted (unsigned 401)` and is terminal
(`internal/agent/claim_loop.go`, `internal/daemon/daemon.go`):

```bash
journalctl -u mucki-agent --since "$START" \
  | grep -E 'MUWA_AGENT_(SIGNATURE_INVALID|TIMESTAMP_OUT_OF_WINDOW|REPLAY_DETECTED|REVOKED)|unsigned 401|key is not accepted' \
  | wc -l                                                                     # 0
```

**No unexpected restarts.** The unit restarts on failure after 10 s and never
on exit code 78 (`agent/packaging/systemd/mucki-agent.service`:
`Restart=on-failure`, `RestartPreventExitStatus=78`).

```bash
systemctl show -p NRestarts -p ExecMainStartTimestamp mucki-agent   # both unchanged since $START
journalctl -u mucki-agent --since "$START" | grep -cE 'msg="agent (started|stopped)"|msg="agent stopped with an error"|msg="agent cannot start"'   # 0
```

Every start or stop in the window must be explained by a documented
operator action (e.g. a policy change with `install.sh`); otherwise restart
the 24-hour window after fixing the cause.

**Clock offset stable within ±60 seconds.** Neither the agent nor the
administration displays the clock offset, so it is measured (observation
loop above). Background from the code:

- The request timestamp window is ±60 s against the PHP clock of the app
  container (`AgentProtocol::TIMESTAMP_WINDOW_SECONDS`, `time()` in
  `AgentAuthenticator::authenticate()`); outside it the request is rejected
  with `MUWA_AGENT_TIMESTAMP_OUT_OF_WINDOW`.
- Responses are signed with the same PHP clock (`X-Mucki-Agent-Timestamp`,
  `AgentResponseSigningSubscriber`); the agent maps the job deadlines onto its
  monotonic clock relative to that signed server time
  (`calibrate` in `internal/agent/runner.go`), so local skew does not stretch
  deadlines.
- Job deadlines, claims, the reaper and the rollback use the database clock
  (`SELECT UTC_TIMESTAMP(3)` in `JobCreator`, `JobClaimer`, `JobReaper`,
  `JobDrainer`). A PHP/database offset shifts deadlines as seen by the agent.

Therefore both offsets are recorded: `php_offset` (app container vs. agent
host) and `db_offset` (MySQL vs. agent host). The criterion is
`|offset| ≤ 60` for **every** sample, and the series must not drift towards the
limit. A docker round trip adds up to about one second of measurement error.
In the documented topology the containers share the host kernel clock, so
both offsets are expected to stay at 0–1 s; a larger value means another
clock source (a remote database or a different host) and must be explained.
In addition the agent host must be NTP-synchronized:

```bash
timedatectl show -p NTPSynchronized --value   # yes
```

If the agent runs on a different host than the stack, take `php_offset` from
the `Date:` header of any MuckiSafe response instead of `docker compose exec`
(one-second resolution):

```bash
echo $(( $(date -u -d "$(curl -fsSI "$MUCKISAFE_URL/admin" | sed -n 's/^[Dd]ate: //p' | tr -d '\r')" +%s) - $(date -u +%s) ))
```

**No open jobs.** At the end of the window:

```bash
console muwa:agent:rollback status; echo "exit $?"   # all open counts 0, exit 0
sudo find /var/lib/mucki-agent/state/jobs -maxdepth 1 -name '*.json' | wc -l   # 0
```

`status` only reads (`AgentRollback::status()`); exit 0 means no job is
`queued`, `claimed`, `running` or `cancel_requested`. The second command is
the agent journal check of `uninstall.sh` (`<stateDirectory>/jobs/<job-id>.json`).

## Stage 3 — migrate exactly one non-critical test repository

Choose one Facility repository whose backup may fail without harm. Do the
migration outside its backup schedule: between deactivating the legacy hooks
and assigning the agent hooks a backup would run without any hook.

1. **Snapshot the legacy hook configuration** ([A.7](#a7-facility-legacy-hooks))
   and store the output with the protocol. This snapshot is what the rollback
   restores: when a hook type is set to `none`, Facility clears its command
   and arguments (`BackupRepository::assertValidHookConfiguration()` in
   MuckiFacilityPlugin), so the old values exist **only** in this snapshot.
2. **Prepare the action(s)** in *Mucki Agent → Actions* and check that the
   agent reports every required capability (tab *Agents*); the hook save
   rejects missing capabilities with `capability_missing`.
3. **Conflict check.** Try to assign the first agent hook (tab *Repository
   hooks*, or `POST /api/_action/muwa/agent/repository-hook`,
   [A.9](#a9-admin-api-examples)) while the legacy hooks are still active.
   Expected: HTTP 409 `{"error":"legacy_hook_conflict","fields":[...]}`
   (`RepositoryHookController`, `LegacyHookConflictGuard`) naming exactly the
   active legacy fields `preBackupCommandType`/`postBackupCommandType` from the
   snapshot. Only a conflict that matches the snapshot is a successful check;
   any other error (`facility_repository_not_found`, `agent_unavailable`, …)
   must be fixed first. Per repository either legacy or agent hooks run,
   never both; only the exact type `none` counts as inactive.
4. **Deactivate the legacy hooks**: set both commands to *No execution*
   (type `none`) in the Facility repository, or `PUT
   /api/_action/muwa/backup/{id}/hooks` with both types `none`
   ([A.9](#a9-admin-api-examples)). Re-run [A.7](#a7-facility-legacy-hooks):
   both types are `none`.
5. **Assign the agent hooks** (pre/post/finally as required). Every save
   returns `{"id": "<hook id>"}`; [A.4](#a4-repository-hooks) lists them with
   `enabled = 1`.
6. Leave the service-specific `systemd.path` kit of this service installed.
   Disable it only after Stage 4 succeeded, and keep its files for the
   rollback (plan, Task 32: "zunächst als Rollback erhalten"):

   ```bash
   sudo systemctl disable --now muckisafe-jellyfin.path   # e.g.; the units stay in /etc/systemd/system
   ```

## Stage 4 — one complete run and one injected failure run

**Complete run.** Trigger the backup of the test repository exactly like a
scheduled or manual one (Facility console command, as in
`tests/E2E/scripts/trigger-backup.sh`):

```bash
console muckiware:backup:create <facility-repository-id>; echo "exit $?"
```

Acceptance:

1. The newest run of the repository ([A.5](#a5-backup-runs)) is `succeeded`
   with `primary_error_code` `NULL`.
2. Its jobs ([A.6](#a6-jobs-of-a-run)) are terminal: one `succeeded` job per
   configured phase (`pre`, `post`, `finally`). The agent journal shows
   `msg="job completed"` with `outcome=` and `terminalStatus=` for each job
   (`internal/agent/runner.go`).
3. No open job remains: `console muwa:agent:rollback status` exits 0.
4. The host end state matches the service contract
   `tests/E2E/contracts/<service>.json`: `startState` holds again,
   `expectedEffects` are present and `cleanup.production` is respected (e.g.
   Jellyfin: unit active and `http://127.0.0.1:8096/System/Info/Public`
   answers 200; Bitwarden: a new non-empty `.BAK` of this run; ACLs are kept
   in production).

**Injected failure run.** The E2E-proven injection is a core failure
(`core_break`/`core_repair` in `tests/E2E/scripts/inject-failure.sh`): make
the restic repository of the test repository unwritable for the backup user,
trigger, repair.

```bash
sudo chmod -R a-w <host path of the restic repository>
console muckiware:backup:create <facility-repository-id>; echo "exit $?"   # non-zero
sudo chmod -R u+w,g+w <host path of the restic repository>
```

Acceptance (`BackupRunCoordinator::finished()`):

1. The new run is `failed` with `primary_error_code = 'backup_failed'`.
2. `pre` is `succeeded`, there is **no** `post` job (Facility does not run the
   after phase when the core fails), `finally` is `succeeded` when configured.
3. All jobs are terminal and `status` exits 0.
4. The host is back in its start state per contract (Jellyfin running again
   through `finally`).

A failing pre action (e.g. the target container stopped) is an alternative
injection: the run ends `failed` with `pre_hook_failed`, the backup does not
start, `finally` still runs. Record which injection was used.

## Stage 5 — further repositories one at a time

Repeat Stages 3 and 4 for each further repository, one repository at a time,
in the order of `docs/design.md` (Bitwarden, Jellyfin, Immich, Paperless).
Do not start the next migration before the current repository has completed
at least one full regular run that meets the Stage 4 acceptance criteria.
Paperless has no working legacy hook (`docs/reference-services.md`, *Frozen
decision for the broken legacy hook*); its snapshot records whatever is
configured, the rollback restores exactly that.

## Rollback

The rollback ends agent hook execution without dropping a running job and
without repeating anything. The switch is a single row in
`muwa_agent_rollback` (`src/Migration/Migration1795478400CreateRollbackState.php`).
While it is active:

- the Facility before subscriber creates no agent run and no job; a backup
  runs like a Facility-only backup and reactivated legacy hooks are no
  conflict (`BackupRunCoordinator::before()`);
- no job is claimed (`JobClaimer`: `NOT EXISTS ... muwa_agent_rollback ...
  active = 1`);
- an already open run skips its remaining `post`/`finally` hooks, logs
  `Agent rollback is active: <phase> hook skipped.` and ends `failed` with
  `primary_error_code = 'rollback_active'` unless an earlier error was
  recorded (`BackupRunCoordinator::runPhase()`).

Three equivalent ways to operate it:

| | Status (role *viewer*) | Start / stop (role *rollback*) |
|---|---|---|
| Console | `console muwa:agent:rollback status [--wait=SECONDS] [--json]` | `console muwa:agent:rollback start [--wait=SECONDS] [--json]`, `console muwa:agent:rollback stop [--json]` |
| Admin API | `GET /api/_action/muwa/agent/rollback` (`muwa_agent.viewer`) | `POST /api/_action/muwa/agent/rollback/start`, `POST /api/_action/muwa/agent/rollback/stop` (`muwa_agent.rollback`) |
| Administration | *Settings → Extensions → Mucki Agent → Rollback* | same tab, needs the additional permission *rollback* |

All three return the same status (`RollbackStatus::toArray()`):

```json
{
    "active": true,
    "startedAt": "2026-11-26 09:15:02.114",
    "stoppedAt": null,
    "openJobs": {"queued": 0, "claimed": 0, "running": 1, "cancel_requested": 0},
    "unknownJobs": 0,
    "readyForHookRemoval": false
}
```

`readyForHookRemoval` is true exactly when all four `openJobs` counts are 0.
`unknownJobs` counts **all** `unknown` jobs in the table, including old ones.
Timestamps are UTC database time.

> **Warning — rollback during a backup.** A rollback started while a backup
> is in progress drains its jobs and skips the remaining `post` and `finally`
> hooks. Whatever the pre action did on the host stays as it is: for example
> Jellyfin stays **stopped**, because the `finally` job that starts it is
> never created. After the drain, check every repository whose run was open
> ([A.5](#a5-backup-runs): runs with `rollback_active` or a drained job,
> [A.8](#a8-jobs-ended-by-the-rollback)) against the `startState` of its
> contract and restore it by hand, e.g.
> `systemctl is-active jellyfin` / `curl -fsS http://127.0.0.1:8096/System/Info/Public`
> and, if needed, `sudo systemctl start jellyfin` (or the legacy utility
> `sudo /usr/local/sbin/jellyfin-service.sh start`, which waits for HTTP).
> Prefer starting the rollback outside the backup windows; [A.5](#a5-backup-runs)
> shows whether a run is open right now.

### Step 0 — record the starting point

```bash
console muwa:agent:rollback status --json | tee rollback-0-before.json
```

Keep the `unknownJobs` baseline, the legacy hook snapshots of every migrated
repository ([A.7](#a7-facility-legacy-hooks), taken in Stage 3) and the list of
hooks ([A.4](#a4-repository-hooks)).

### Step 1 — switch on and drain

```bash
console muwa:agent:rollback start --json | tee rollback-1-start.json
```

`start` commits the switch first, then drains (`AgentRollback::start()`,
`JobDrainer::drain()`):

- `queued` and never started `claimed` jobs become `timed_out` with
  `result_code = 'rollback'`; their claim token is revoked, so a late start or
  completion is fenced off;
- `running` jobs become `cancel_requested`; the agent sees `stopRequested` in
  its next lease heartbeat, logs `msg="stopping job execution"` and reports a
  timeout or ambiguous completion (`timed_out` or `unknown`); at the latest
  the reaper ends the job at its phase deadline as `unknown`;
- terminal jobs, `unknown` and `cancel_requested` jobs are never touched;
- every run that is still open is marked `rollback_active` at once, so a run
  whose post/finally phases are skipped never closes as `succeeded`.

**A job that had already finished on the host.** If the action completed just
before the stop reached the agent, the agent reports `succeeded`/`failed`;
in `cancel_requested` the control plane accepts only a timeout or ambiguous
completion and rejects it (agent journal: `completion rejected, the control
plane has decided the job`). The job then stays `cancel_requested` until its
phase deadline (up to 30 minutes for pre/post, 10 for finally) and ends
`unknown` — although the host action may have succeeded. That is why Step 2
waits up to the longest budget and Step 3 assesses every `unknown` job on the
host instead of trusting the status.

Job creation is serialized against the switch (the job creator reads it with a
shared lock in its transaction), so no job can appear after `start`. `start`
is still repeatable: it keeps the first `startedAt` and drains again.

### Step 2 — wait until no job is open

```bash
console muwa:agent:rollback status --wait=1900 --json | tee rollback-2-ready.json; echo "exit $?"
```

Exit 0 = `readyForHookRemoval`; exit 3 = jobs are still open after the wait.
`--wait` polls every 2 seconds. A `cancel_requested` job ends at the latest at
its `phase_deadline` (budget up to 1800 s for pre/post, 600 s for finally,
`JobPhase::maxBudgetSeconds()`), plus up to one interval of the reaper
(scheduled task, every 60 s). [A.3](#a3-open-jobs-and-their-deadlines) shows
the remaining time. If exit 3 persists after the last phase deadline, check
that the scheduler container runs `scheduled-task:run`; never edit job rows.

Gate: continue **only** when `status` exits 0, i.e. `claimed`, `running`,
`cancel_requested` (and `queued`) are exactly 0.

### Step 3 — assess `unknown` jobs manually

Every job that is `unknown` and newer than the baseline of Step 0
([A.8](#a8-jobs-ended-by-the-rollback)) is assessed by hand and **never
repeated automatically** — `unknown` means the host effect may or may not have
happened:

1. Read the job and its run back ([A.6](#a6-jobs-of-a-run)): phase,
   `idempotency_class`, `attempt`, `result_code`, `result_summary`,
   `claimed_at`, `heartbeat_at`, `finished_at`.
2. Read the failure output with the role *executor*
   (`GET /api/_action/muwa/agent/job/{id}/output`, [A.9](#a9-admin-api-examples)).
3. Read the agent side: `journalctl -u mucki-agent | grep <dashed job id>`
   and, if still present, `sudo cat /var/lib/mucki-agent/state/jobs/<dashed job id>.json`.
4. Check the host against the contract of the service (e.g. was the Bitwarden
   `.BAK` written, is Jellyfin running, did Immich publish a dump).
5. Restore the start state by hand where needed and record the decision per
   job. Do not re-trigger the job or the backup to "repair" it.

### Step 4 — remove the agent hooks

For every migrated repository delete all its agent hooks: tab *Repository
hooks* → delete, or `DELETE /api/_action/muwa/agent/repository-hook/{id}`
(role *editor*, [A.9](#a9-admin-api-examples)). Deleting keeps the jobs that
were already created. Verify with [A.4](#a4-repository-hooks): no row left.
Without enabled hooks the before subscriber does nothing at all, so from now
on the switch is no longer needed to keep the agent out of backups.

### Step 5 — reactivate the legacy hooks and kits

1. Restore every repository's legacy hooks **exactly** from the Stage 3
   snapshot: in the Facility repository (type, command, arguments, fail on
   error for before and after), or with one `PUT
   /api/_action/muwa/backup/{id}/hooks` carrying all eight fields
   ([A.9](#a9-admin-api-examples)) — fields that are left out are reset to
   `none`. Compare [A.7](#a7-facility-legacy-hooks) with the snapshot.
2. Re-enable the host kits that were disabled and check that their files are
   still in place:

   ```bash
   systemctl list-unit-files 'muckisafe-*'
   sudo systemctl enable --now muckisafe-bitwarden-prep.path muckisafe-jellyfin.path   # the kits in use
   systemctl status muckisafe-jellyfin.path                                           # active (waiting)
   ```

   The container scripts must still exist in the scripts directory
   (`/scripts/bitwarden-prep.sh`, `/scripts/jellyfin-control.sh`).

### Step 6 — verify a legacy backup run

For each restored repository:

```bash
sql <<<'SELECT COUNT(*) FROM muwa_agent_backup_run'          # note the count
console muckiware:backup:create <facility-repository-id>; echo "exit $?"   # 0
sql <<<'SELECT COUNT(*) FROM muwa_agent_backup_run'          # unchanged
```

Acceptance: exit 0, no new agent run or job, the kit worked on the host
(`journalctl -u muckisafe-<kit>.service` shows the run; for Jellyfin the
service is running again), and the new restic snapshot exists (Facility
repository snapshots). This is the legacy behaviour of the kit READMEs
("restic must finish with exit 0").

### Step 7 — switch off

```bash
console muwa:agent:rollback stop --json | tee rollback-7-stop.json   # "active": false, "stoppedAt": ...
```

When `stop` is right:

- **After a completed rollback**, i.e. after Step 4 removed every agent hook.
  With no enabled hook the before subscriber returns immediately, so
  reactivated legacy hooks cannot conflict. `stop` before Step 4 would let the
  remaining agent hooks run again and, next to reactivated legacy hooks, fail
  the backup with `legacy_hook_conflict`. Stopping after Step 6 keeps the
  switch as a second safeguard during the legacy verification.
- **When the rollback is aborted** and the agent stays in charge: first make
  sure the legacy hooks of every migrated repository are `none` again and the
  agent hooks exist and are enabled, then `stop`. Drained jobs stay terminal;
  the next backup creates a new run.

`stop` always exits 0 and is idempotent; it changes only an active switch.

### Step 8 — keep the agent

Leave binary, `/etc/mucki-agent` (config, secret, policy) and the journal
`/var/lib/mucki-agent/state` in place. The idle agent keeps sending
heartbeats; it gets no job because no hook exists.

### Step 9 — uninstall (separate, last step)

Only after at least one successful legacy run per restored repository
(Step 6), from the release directory of the installed version
(`agent/packaging/uninstall.sh`):

```bash
sudo find /var/lib/mucki-agent/state/jobs -maxdepth 1 -name '*.json' | wc -l   # 0
sudo ./uninstall.sh            # service and binary; keeps config, secret, policy, journal, wakeup directory
sudo ./uninstall.sh --purge    # later, only after a successful legacy run: removes config and journal
```

`--purge` refuses (exit 1) while the journal still holds job records. The
wakeup directory is removed only when empty, because the containers mount
it. Revoking the agent key in the administration (tab *Agents*, role
*provisioner*) is optional and cannot be undone.

## Appendix

### A.1 Agent hosts and heartbeat age

```sql
SELECT LOWER(HEX(id)) AS host_id, name, key_id, enabled, revoked_at, version,
       last_seen_at, TIMESTAMPDIFF(SECOND, last_seen_at, UTC_TIMESTAMP(3)) AS age_seconds,
       capabilities
FROM muwa_agent_host
ORDER BY name;
```

### A.2 Rollback switch and open jobs

The readback of `AgentRollback::status()`:

```sql
SELECT active, started_at, stopped_at, updated_at FROM muwa_agent_rollback WHERE id = 1;

SELECT status, COUNT(*)
FROM muwa_agent_job
WHERE status IN ('queued', 'claimed', 'running', 'cancel_requested', 'unknown')
GROUP BY status;
```

### A.3 Open jobs and their deadlines

```sql
SELECT LOWER(HEX(id)) AS job_id, LOWER(HEX(backup_run_id)) AS run_id, phase, status,
       attempt, max_attempts, claimed_at, heartbeat_at, lease_expires_at, phase_deadline,
       TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(3), phase_deadline) AS seconds_to_phase_deadline
FROM muwa_agent_job
WHERE status IN ('queued', 'claimed', 'running', 'cancel_requested')
ORDER BY phase_deadline;
```

### A.4 Repository hooks

```sql
SELECT LOWER(HEX(h.id)) AS hook_id, LOWER(HEX(h.facility_repository_id)) AS repository_id,
       r.name AS repository, h.phase, h.enabled, h.fail_on_error, h.timeout_seconds,
       a.technical_name AS action, ah.name AS agent
FROM muwa_agent_repository_hook h
JOIN muwa_agent_action a ON a.id = h.agent_action_id
JOIN muwa_agent_host ah ON ah.id = h.agent_host_id
LEFT JOIN muwa_backup_repository r ON r.id = h.facility_repository_id
ORDER BY repository, h.phase;
```

### A.5 Backup runs

`status` is one of `running`, `succeeded`, `failed`, `unknown`. Primary error
codes: `backup_failed`, `pre_hook_failed`, `post_hook_failed`,
`finally_hook_failed`, `rollback_active`, `orphaned_run`. A run that stays
`running` without an active backup is closed as `unknown` (`orphaned_run`) by
the next agent-hooked backup; it does not block the rollback.

```sql
SELECT LOWER(HEX(id)) AS run_id, LOWER(HEX(facility_repository_id)) AS repository_id,
       status, started_at, finished_at, primary_error_code, primary_error_summary
FROM muwa_agent_backup_run
-- WHERE facility_repository_id = UNHEX('<repository id>')
-- WHERE status = 'running'
ORDER BY created_at DESC
LIMIT 10;
```

### A.6 Jobs of a run

```sql
SELECT LOWER(HEX(id)) AS job_id,
       LOWER(CONCAT_WS('-', SUBSTR(HEX(id), 1, 8), SUBSTR(HEX(id), 9, 4), SUBSTR(HEX(id), 13, 4),
                            SUBSTR(HEX(id), 17, 4), SUBSTR(HEX(id), 21))) AS wire_job_id,
       phase, status, idempotency_class, attempt, max_attempts, result_code, result_summary,
       claimed_at, heartbeat_at, finished_at, phase_deadline
FROM muwa_agent_job
WHERE backup_run_id = UNHEX('<run id>')
ORDER BY created_at;
```

Terminal job states: `succeeded`, `failed`, `timed_out`, `expired`, `unknown`
(`JobWaiter::TERMINAL_STATUSES`).

### A.7 Facility legacy hooks

Snapshot before Stage 3, comparison in rollback Step 5:

```sql
SELECT LOWER(HEX(id)) AS repository_id, name, active,
       pre_backup_command_type, pre_backup_command, pre_backup_command_arguments, pre_backup_fail_on_error,
       post_backup_command_type, post_backup_command, post_backup_command_arguments, post_backup_fail_on_error
FROM muwa_backup_repository
WHERE id = UNHEX('<repository id>');
```

### A.8 Jobs ended by the rollback

```sql
-- queued/claimed jobs the drain timed out
SELECT LOWER(HEX(id)) AS job_id, LOWER(HEX(backup_run_id)) AS run_id, phase, status, finished_at
FROM muwa_agent_job
WHERE result_code = 'rollback';

-- unknown jobs since the rollback started (manual assessment, Step 3)
SELECT LOWER(HEX(j.id)) AS job_id, LOWER(HEX(j.backup_run_id)) AS run_id, j.phase,
       j.idempotency_class, j.result_code, j.updated_at
FROM muwa_agent_job j
JOIN muwa_agent_rollback r ON r.id = 1
WHERE j.status = 'unknown' AND j.updated_at >= r.started_at;
```

### A.9 Admin API examples

Get a token with an integration (*Settings → System → Integrations*) whose
role grants the needed permissions — *Mucki Agent* viewer/editor, the
additional permission *rollback* (and *executor* for job output), and for the
Facility hook route `muwa_backup_repository:execute_commands` — or an
administrator integration. Never put the secret into the shell history or a
file:

```bash
MUCKISAFE_URL=https://muckisafe.example.com
read -r -p 'Access key ID: ' CLIENT_ID
read -r -s -p 'Secret access key: ' CLIENT_SECRET; echo
# Secret and token never appear as process arguments (visible in ps):
# jq reads the secret from its environment, curl the header from stdin.
TOKEN=$(CLIENT_SECRET="$CLIENT_SECRET" jq -n --arg id "$CLIENT_ID" \
          '{grant_type: "client_credentials", client_id: $id, client_secret: $ENV.CLIENT_SECRET}' \
        | curl -fsS -H 'Content-Type: application/json' --data-binary @- "$MUCKISAFE_URL/api/oauth/token" \
        | jq -r .access_token)
unset CLIENT_SECRET
api() { printf 'Authorization: Bearer %s\n' "$TOKEN" | curl -sS -H @- -H 'Content-Type: application/json' -H 'Accept: application/json' "$@"; }
```

The token expires after a few minutes; request a new one when calls return
401.

Rollback (all answers carry `Cache-Control: no-store`; an internal error is
HTTP 500 `{"error":"operation_failed"}`):

```bash
api "$MUCKISAFE_URL/api/_action/muwa/agent/rollback"                  # status
api -X POST "$MUCKISAFE_URL/api/_action/muwa/agent/rollback/start"    # switch on + drain
api -X POST "$MUCKISAFE_URL/api/_action/muwa/agent/rollback/stop"     # switch off
```

Repository hooks (`RepositoryHookController`, role *editor*; IDs are 32
lowercase hex characters):

```bash
# assign / replace the hook of one phase -> {"id": "..."}; 409 {"error":"legacy_hook_conflict","fields":[...]}
api -X POST "$MUCKISAFE_URL/api/_action/muwa/agent/repository-hook" --data-binary '{
  "facilityRepositoryId": "<repository id>", "phase": "pre",
  "agentHostId": "<host id>", "agentActionId": "<action id>",
  "timeoutSeconds": 600, "failOnError": true, "enabled": true}'

# remove -> {"deleted": true}; 404 {"error":"hook_not_found"}
api -X DELETE "$MUCKISAFE_URL/api/_action/muwa/agent/repository-hook/<hook id>"
```

Facility legacy hooks (MuckiFacilityPlugin `BackupRepositoryHooksController`,
privilege `muwa_backup_repository:execute_commands`, HTTP 204 on success).
The route always writes both positions; omitted fields fall back to type
`none`:

```bash
# deactivate (Stage 3)
api -X PUT "$MUCKISAFE_URL/api/_action/muwa/backup/<repository id>/hooks" \
  --data-binary '{"preBackupCommandType": "none", "postBackupCommandType": "none"}'

# restore from the snapshot (rollback Step 5), e.g. the Jellyfin kit
api -X PUT "$MUCKISAFE_URL/api/_action/muwa/backup/<repository id>/hooks" --data-binary '{
  "preBackupCommandType": "batch", "preBackupCommand": "/scripts/jellyfin-control.sh",
  "preBackupCommandArguments": "stop", "preBackupFailOnError": true,
  "postBackupCommandType": "batch", "postBackupCommand": "/scripts/jellyfin-control.sh",
  "postBackupCommandArguments": "start", "postBackupFailOnError": true}'
```

Readback through the generic API (role *viewer*):

```bash
api -X POST "$MUCKISAFE_URL/api/search/muwa-agent-job" --data-binary '{
  "filter": [{"type": "equalsAny", "field": "status", "value": ["queued", "claimed", "running", "cancel_requested"]}],
  "total-count-mode": 1}' | jq '.total'

api -X POST "$MUCKISAFE_URL/api/search/muwa-agent-backup-run" --data-binary '{
  "sort": [{"field": "createdAt", "order": "DESC"}], "limit": 5}' | jq '.data[] | {id, status, primaryErrorCode}'

# failure output of one job (role executor)
api "$MUCKISAFE_URL/api/_action/muwa/agent/job/<job id>/output"
```

Trigger a backup asynchronously (Facility, privilege
`muwa_backup_repository:backup`, plus `execute_commands` while legacy hooks are
configured; HTTP 204, the scheduler runs it):

```bash
api -X POST "$MUCKISAFE_URL/api/_action/muwa/backup/process" --data-binary '{"id": "<repository id>"}'
```

### A.10 Exit codes

| Command | Code | Meaning |
|---|---|---|
| `muwa:agent:rollback start\|status` | 0 | no job is `queued`, `claimed`, `running` or `cancel_requested` (`readyForHookRemoval`) |
| | 3 | jobs are still open, also after `--wait` (`RollbackCommand::EXIT_OPEN_JOBS`) |
| `muwa:agent:rollback stop` | 0 | switch is off (also when it was already off) |
| `muwa:agent:rollback` | 2 | usage error: unknown action or `--wait` not a non-negative integer |
| `mucki-agent` (unit) | 78 | unsafe/invalid installation or key not accepted; systemd does not restart (`RestartPreventExitStatus=78`) |
| | 1 | any other failure; systemd restarts after 10 s |
| `uninstall.sh` | 1 | not root, unknown argument, or `--purge` while the journal holds job records |
