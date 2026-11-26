# Linux service E2E (Task 32)

Real end-to-end acceptance of MuckiSafe (its own `docker-compose.yml`),
MuckiFacilityPlugin, this plugin and the packaged host agent under systemd,
against the four reference services Bitwarden, Jellyfin, Immich and Paperless.

```bash
# static self-test (no root, no docker; also on macOS)
tests/E2E/scripts/selftest.sh

# one service on the native architecture (root, disposable Linux host)
sudo tests/E2E/scripts/run-linux-e2e.sh --arch amd64 --scenario immich
sudo tests/E2E/scripts/run-linux-e2e.sh --arch amd64 --scenario immich \
    --muckisafe-mode release --agent-mode release      # Gate E
tests/E2E/scripts/run-linux-e2e.sh --dry-run --arch arm64 --scenario jellyfin   # plan only
```

Gate E only accepts `--muckisafe-mode release --agent-mode release` runs of all
scenarios on native amd64 and arm64 (`[self-hosted, linux, arm64,
muckisafe-e2e]`). **Bitwarden runs on amd64 only**: the pinned
`mcr.microsoft.com/mssql/server` image is published for linux/amd64 only;
its arm64 run is reported as skipped with that reason (decision 2026-11-25).

The service E2E is not part of the GitHub workflows (decision 2026-11-26):
it runs manually on a disposable native Linux host as shown above.

## Layout

| Path | Content |
|---|---|
| `images.lock`, `versions.env` | immutable pins (tag@sha256, no `latest`); `MUCKISAFE_COMPOSE_REF` is the MuckiSafe commit under test |
| `contracts/<service>.json` | Task 10A inventory of the real service |
| `fixtures/<service>/fixture.json` | start state, cases (`inject`/`expect`), cleanup and end state |
| `compose/compose.yaml` | override of MuckiSafe's compose file: image under test, repository dir, scheduler every minute, Caddy TLS proxy |
| `compose/Dockerfile.agent-plugin` | source mode: pinned MuckiSafe base image + this plugin via Composer path repository |
| `scripts/run-linux-e2e.sh` | orchestrator |
| `scripts/lib.sh` | logging, JUnit, waiting, pins/fixtures, compose/SQL/API helpers, manifests |
| `scripts/setup.sh` | stack, CA trust, agent install/provisioning (documented operator flow), repository, actions, hooks |
| `scripts/trigger-backup.sh` | real backup (`bin/console muckiware:backup:create`) and run/job read-back |
| `scripts/assert-effects.sh` | generic assertions (phase jobs, core outcome, exit code, open jobs) |
| `scripts/inject-failure.sh` | generic injectors and the resilience cases |
| `scripts/rollback.sh` | the controlled rollback of Task 33 played through (step `rollback`) |
| `scripts/cleanup.sh` | artifacts and teardown |
| `scenarios/<service>.sh` | the service: implements the interface below |

## Run

1. **preflight** — root, Linux, native `--arch`, systemd, tools, selftest.
2. **stack** — MuckiSafe checkout at `MUCKISAFE_COMPOSE_REF`, generated
   `stack.env` (fresh secrets and keyring), wakeup directory `root:33 0770`,
   scenario source mounts, Caddy CA trusted on the host.
3. **agent** — `install.sh --no-start`, provisioning via the admin API,
   secret/config (HTTPS)/policy, second `install.sh` run starts the unit.
4. **service** — `scenario_up`.
5. **backup** — `scenario_seed`, Facility repository, one action + hook per
   `scenario_hooks` line.
6. **cases** — every fixture case: `scenario_inject` → backup →
   `assert_case` (generic) → `scenario_assert_effects` → `scenario_reset`.
7. **resilience** — agent offline (back in time / past the deadline), API
   offline, lost and duplicated wakeups, agent restart, lease expiry with an
   old worker, completion retry, core failure — each with
   `SCENARIO_SUCCESS_CASE`.
8. **transport** — explicit HTTP: backup succeeds, the agent journal shows
   the warning about readable actions, parameters and error output.
9. **upgrade** — `docker compose pull && up -d` keeps binary, config,
   secret, journal and wakeup mount; the success case passes again.
10. **facility-only** — AgentPlugin deactivated: a Facility backup succeeds
    and creates no agent run.
11. **rollback** — the controlled rollback (Task 33,
    `docs/rollout-runbook.md`) with `SCENARIO_SUCCESS_CASE`: the first job is
    stalled while `running`, `muwa:agent:rollback start` turns it into
    `cancel_requested` (exit 3), the agent journal shows `stopping job
    execution` (`stop requested by the control plane`), `rollback status
    --wait=180` exits 0, the run ends `failed`/`unknown` and the job
    `timed_out`/`unknown`. With the switch active a backup creates no run
    and no job but a snapshot. The repository's hooks are deleted over the
    admin API, `rollback stop`, and the plain Facility backup again creates
    no run but a snapshot (the E2E has no legacy hooks or systemd.path kits
    to re-enable). The saved hooks are restored afterwards, so the step can
    be repeated; `inject_reset_all` also stops an active switch and restores
    the hooks of an aborted rollback.
12. **cleanup** — artifacts (`junit.xml`, `logs/`, `runs/`, `manifests/`,
    `cases/`) to `--out`, teardown.

Every check runs in a subshell with `set -euo pipefail` in effect and stdin
from `/dev/null`: state shared between steps lives in files under `WORK_DIR`
(`agent-host-id`, `agent-key-id`, `repository-id`, `repository-password`,
`agent-package-dir`, `agent-binary`). `check` always returns 0 and stores the
result in `CHECK_STATUS` — never call it in an `||`/`&&`/`if` context, bash
would silently disable `set -e` inside.

Facts the harness relies on (verified against a running stack): a fresh
Facility is disabled (`MuckiFacilityPlugin.config.active`; `stack_up` enables
it, otherwise `backup:create` skips silently with exit 0); a files-only
repository has type `noneDatabase` (`files` also dumps the MuckiSafe
database); `muwa_agent_host` has no status column (`agent_active` = enabled,
not revoked, `last_seen_at` after `agent_mark_restart`); async backups use
`backup_trigger_async` (sets `BACKUP_PID`) and `backup_wait`, and
`assert_case` requires a new run per trigger.

## Scenario interface

`scenarios/<service>.sh` is sourced after `lib.sh` and all step scripts (it
may use every helper). It must define:

| Name | Contract |
|---|---|
| `SCENARIO_ARCHES` | `"amd64 arm64"`, or `"amd64"` with a `scenario_skip_reason` override |
| `SCENARIO_SUCCESS_CASE` | name of the fixture case the resilience, transport and upgrade steps use |
| `SCENARIO_DEFAULT_PHASE` | phase a top-level `expect.outcome/resultCode/summaryPrefix` belongs to (default `pre`) |
| `scenario_images` | names of the `images.lock` variables it pulls (one per line) |
| `scenario_source_mounts` | host paths MuckiSafe must read (mounted read-only at the same path into app and scheduler) |
| `scenario_backup_paths` | Facility backup paths (container = host paths) |
| `scenario_policy` | writes the host policy for this service (derived from `examples/policies/linux-host.yaml`, only this service's aliases) and prints its path |
| `scenario_hooks` | one line per hook: `PHASE ACTION_FILE IDEMPOTENCY_CLASS TIMEOUT_SECONDS FAIL_ON_ERROR` (action files from `examples/actions/`; timeouts 45–120 s keep the run bounded) |
| `scenario_up` / `scenario_down` | start the pinned service in its documented start state / remove it completely |
| `scenario_seed` | create the fixture `seed` and store the baseline manifest (`manifest_save seed ...`) |
| `scenario_cases` | the fixture case names (normally `fixture_cases`) — the selftest requires exactly the fixture's cases |
| `scenario_inject CASE` | apply the case's `inject` (fixture semantics, see the agent reference tests) |
| `scenario_assert_effects CASE` | service-specific `expect` keys and host effects (dump, unit state, HTTP health, retention, ACLs, `core: readable` content) |
| `scenario_reset CASE` | undo the injection, run the documented `cleanup`, prove the fixture `endState` (`manifest_save` + `manifest_equal`) |
| `scenario_stall on\|off` | make the first host effect of the first hooked phase hang until `off` (deterministic timing for restart, lease and completion cases) |
| `scenario_skip_reason ARCH` | optional; prints a reason to skip ARCH (Bitwarden: `arm64`) |
| `scenario_prepare_host` | optional; runs before `docker compose up` and must create every `scenario_source_mounts` path — the stack never creates bind sources (`create_host_path: false`) |
| `scenario_collect_logs DIR` | optional; service logs into the artifact directory |

`assert_case` already checks per phase `status == outcome`, `result_code`,
the `result_summary` prefix, the core outcome (`readable`/`succeeded`,
`failed`, `skipped`) and that the console exit code matches the run.
Service-specific keys (`dump`, `unit`, `healthStatus`, `invoked`,
`removedHistory`, …) are the scenario's job.

A service that is not a container (Jellyfin is a native systemd unit) keeps
its own pins in `fixtures/<service>/` (e.g. `package.env`: package versions
and SHA-256 of the files); helper scripts there are shellchecked too. For
native services a fixture health status other than 200 means "not
reachable" (curl `000` or 5xx), since no fake answers in the real run.

Rules: images only from `images.lock`; no `latest`; no host paths outside the
fixture's policy roots and `WORK_DIR`; nothing that needs a real secret;
`E2E_TODO` markers fail the selftest.
