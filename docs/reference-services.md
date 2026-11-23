# Reference service execution contracts

This document records the four Gate-B reference workloads before the Action DSL
is defined. It is an **evidence-only, untested inventory**: live-host access and
execution/E2E tests were explicitly waived. The reference execution contracts
are fixed by this inventory. Task 10B incorporated the two amendments below
into the frozen V1 Action DSL and its negative fixtures. The machine-readable
expected-effect and cleanup contracts live in
`tests/E2E/contracts/`. Human-readable versions are in
`tests/E2E/versions.env`; every OCI image used by the fixtures is pinned by an
exact tag and registry digest in `tests/E2E/images.lock`.

## Evidence boundary

The inventory is based on:

- the committed MuckiSafe preparation kits under
  `MuckiSafe/docs/{bitwarden,jellyfin,immich}-backup-prep/`;
- the supplied Paperless and Immich Compose snapshots and MuckiSafe deployment
  notes;
- the reachable read-only public endpoints of the four reference services;
- the normative primitive policy in `docs/implementation-plan.md`.

No action definition accepts a host path, unit name, URL, container name, UID,
executable or free-form command from the server. Those values and the `aliases`
shown in the inventory manifests are local agent-policy input, not serialized
action fields. Credentials remain server-side secret references and never
enter an action definition, result, fixture or log.

The legacy Paperless hook is deliberately not treated as a successful reference
contract. The only evidenced invocation is `/bin/bash /scripts/paperless_pre.sh`
with no script arguments; it fails at line 8 because bare `${2}` is expanded
under `set -u`. The real script and its missing argument meanings are not
available, and Task 10A forbids inventing them. The frozen Paperless success
path therefore uses the existing typed MuckiSafe database backup plus file
backup and does not execute the broken shell hook.

## Frozen reference versions

| Service | Version evidence | Frozen reference |
|---|---|---|
| Bitwarden | `GET /api/config` | `2026.10.1` |
| Jellyfin | `GET /System/Info/Public` | `12.4.0` |
| Immich | `GET /api/server/version` | `3.3.5` |
| Paperless-ngx | supplied version-pinned Compose | `3.1.3` |

The versions describe the reference behavior. E2E images are separate immutable
fixture selections and must always be loaded through `images.lock`; mutable
`latest`, `release` and major-only references are forbidden.

## Requirement → primitive → E2E matrix

| Service requirement | Primitive/core operation | Policy aliases | E2E proof |
|---|---|---|---|
| Bitwarden creates a fresh MSSQL full backup | `docker.container.exec` | `bitwarden-mssql`, `bitwarden-full-backup` | fixed `/backup-db.sh` exits 0; a non-empty `.BAK` created by this run exists |
| Bitwarden restore-critical files are readable by the backup user | `filesystem.acl.read`, `filesystem.assert-readable` | `bitwarden-data`, `muckisafe-backup`, policy check sets | current `.BAK`, `global.override.env`, `identity.pfx` and a data-protection XML key are readable as UID 33 |
| Bitwarden complete source is saved | `muckisafe.backup` | `bitwarden-data` | core backup exits 0 and includes the entire `bwdata` tree, including attachments |
| Bitwarden remains available | `service.wait-http` | `bitwarden-config` | bounded `/api/config` response is HTTP 200 and reports `2026.10.1` |
| Jellyfin SQLite is quiescent | `service.control` | `jellyfin` | unit is inactive and its cgroup has no processes before the backup begins |
| Jellyfin data/config are readable | `filesystem.acl.read`, `filesystem.assert-readable` | `jellyfin-data`, `jellyfin-config`, `muckisafe-backup` | database, data and config walk succeeds as UID 33 |
| Jellyfin comes back after every outcome | `service.control`, `service.wait-http` in `finally` | `jellyfin`, `jellyfin-public-info` | unit is active and public-info returns HTTP 200 after success or failure |
| Immich produces an online-consistent database dump | `file.write-pipeline` | `immich-pg-dump`, `immich-manual-backups` | atomic non-empty gzip output is recorded in the run manifest |
| Immich keeps only owned manual dumps | `file.retention` | `immich-manual-backups` | only manifest-owned old manual dumps are removed; automatic dumps survive |
| Immich source trees are readable | `filesystem.acl.read`, `filesystem.assert-readable` | upload and optional derivative path aliases | bounded recursive walk and current-run dump check succeed as UID 33 |
| Immich data and dump are saved without downtime | `muckisafe.backup` | Immich source aliases | core backup exits 0, contains current dump and services remain running |
| Immich remains available | `service.wait-http` | `immich-version` | local version endpoint is HTTP 200 and reports `3.3.5` |
| Paperless database is dumped through the existing typed core path | `muckisafe.database-backup` | `paperless-database` | scheduler resolves `paperless-db`, has `/usr/bin/mysqldump`, and emits a non-empty backup artifact |
| Paperless data roots are saved | `filesystem.assert-readable`, `muckisafe.backup` | four Paperless path aliases, `muckisafe-backup` | all four source walks and the core backup succeed without permission errors |
| Paperless remains available | `service.wait-http` | `paperless-web` | fixed endpoint returns HTTP 200 or the expected login redirect 302 |

No row requires a generic shell primitive.

## Primitive-contract amendments incorporated by the Task-10B DSL freeze

The seven primitive families are sufficient from the available evidence. The
V1 schema is frozen only after incorporating these two normative changes, their
threat analysis and shared negative fixtures:

1. `filesystem.assert-readable` must accept a **policy-defined check alias**.
   A check alias may select a bounded relative directory, fixed suffix and
   required count, require a regular file created by the current run, enforce a
   minimum size, name one path alias and name a fixed principal. Multiple roots
   are represented by multiple primitive steps; no plural/free path input is
   added. The action cannot supply a glob, prefix/suffix, absolute path or UID.
   This is required to prove that Bitwarden created a current, non-empty `.BAK`
   instead of accepting a stale backup.
2. `service.control(..., operation=stop)` must not report success merely because
   `systemctl stop` exited 0. Policy can require the exact unit to be inactive
   and its systemd cgroup to contain no remaining processes. This preserves the
   Jellyfin SQLite consistency contract without adding a process-name or shell
   primitive.

The existing `file.write-pipeline` manifest is the authoritative producer for
Immich retention. `file.retention` may delete only files whose alias, relative
name, device, inode and run ID still match that root-protected manifest.

## Bitwarden

### Reference invocation and paths

The legacy MuckiSafe pre-hook is `/scripts/bitwarden-prep.sh`, with no positional
arguments. It writes markers under `${PREP_DIR:-/scripts/.prep}`. The host-side
oneshot `/usr/local/bin/muckisafe-bitwarden-prep` invokes
`docker exec -i bitwarden-mssql /backup-db.sh` and grants `u:33:rX`
recursively on `${BW_DATA_HOST:-/opt/bitwarden}`.

The container view defaults to `/opt/bitwarden/bwdata`. Restore-critical paths
are:

- `mssql/backups/*.BAK` — the database restore source;
- `env/global.override.env`;
- `identity/identity.pfx`;
- `core/aspnet-dataprotection/*.xml`;
- `core/attachments/` and the remainder of the `bwdata` tree.

### Lifecycle and idempotency

The action is **additive**: every run requests another full backup. Once the
fixed backup command starts, retries are non-retryable; a crash is `unknown`
until the journal identifies the produced artifact. The ACL and
assertion steps are convergent/read-only. Runs are serialized per Bitwarden
installation. Success requires the fixed command to exit 0, a non-empty `.BAK`
created by this run, readability of every mandatory path as UID 33, and a core
backup exit code of 0. No dump-content comparison is defined.

Bitwarden remains running throughout and the fixed config endpoint must remain
healthy. Production keeps generated `.BAK` files
and ACLs. E2E cleanup removes only current-test artifacts and restores the
fixture's prior ACL state.

## Jellyfin

### Reference invocation and paths

Jellyfin is a native systemd service. The legacy pre/post hooks are:

- `/scripts/jellyfin-control.sh stop`;
- `/scripts/jellyfin-control.sh start`.

The frozen local aliases bind:

- unit `jellyfin.service`;
- data `/var/lib/jellyfin`;
- config `/etc/jellyfin`;
- database `data/jellyfin.db` beneath the data root;
- health URL `http://127.0.0.1:8096/System/Info/Public`;
- backup principal UID/GID 33.

### Lifecycle and idempotency

Stop and start are **safe/idempotent**. Pre-backup stops the unit, proves the
unit cgroup empty, grants bounded recursive read ACLs and walks the required
paths as UID 33. The core backup saves data and config while SQLite is
quiescent. Media libraries are outside this contract; `transcodes` is excluded.

A `finally` action always starts Jellyfin and waits for HTTP 200, even when pre
or core backup failed. The primary backup error remains primary when restart
also fails. Production keeps the ACLs; E2E cleanup restores the initial service
and ACL state.

## Immich

### Reference invocation and paths

The legacy hook `/scripts/immich-prep.sh` has no positional arguments. Its host
oneshot reads `UPLOAD_LOCATION`, optional derivative paths, database name and
user from local policy/the Compose environment. The frozen action does not
transport those absolute paths or credentials.

The current reference uses:

- container `immich_postgres`;
- fixed local-policy argv `pg_dump --clean --if-exists
  --dbname=${policy.database} --username=${policy.username}`; database and user
  are fixed policy values and cannot be supplied by an action; authentication
  is the fixed container's in-container PostgreSQL configuration, with no
  password/action environment transported;
- destination `<UPLOAD_LOCATION>/backups`;
- output `immich-db-dump-manual-<run-id>.sql.gz`;
- keep count 10 for agent-manifest-owned manual dumps.

`UPLOAD_LOCATION` is required. Thumbnails, encoded video and profile roots are
optional aliases when they lie outside the upload root. `DB_DATA_LOCATION` is
never copied while PostgreSQL is running. `EXTERNAL_PATH` belongs to the backup
of the external library's source, not this contract.

### Lifecycle and idempotency

The action is **additive**. After the first published file, retries are
non-retryable; a crash is `unknown` until journal reconciliation. The database
name and user are fixed local-policy bindings, not action parameters.
PostgreSQL and all Immich services remain online.
`file.write-pipeline` writes gzip itself, enforces size/mode/owner limits,
fsyncs, and publishes with no-replace semantics. ACL and bounded readability
checks follow; then the normal backup must include the current-run dump.

A partial failure may intentionally leave a completed dump. Cleanup never
removes unmanifested or Immich-managed automatic backups.

## Paperless

### Frozen decision for the broken legacy hook

The observed caller executes:

```text
/bin/bash /scripts/paperless_pre.sh
```

with no script arguments. The observed result is exit 1 with
`line 8: 2: unbound variable`. No meaning for `$1` or `$2` is evidenced. The
legacy hook is therefore excluded from the success contract rather than repaired
by guessing an argument or introducing a generic shell primitive.

### Reference stack and backup path

The version-pinned reference stack is Paperless-ngx 3.1.3 with MariaDB 12,
Valkey 9, Gotenberg 8.34 and Tika 3.3.1.0. The web service publishes port 8091.
File roots are:

- `/data/dokumente/data`;
- `/data/dokumente/media`;
- `/data/dokumente/export`;
- `/data/dokumente/consume`.

The existing typed database-backup path runs in the MuckiSafe scheduler, uses
`/usr/bin/mysqldump`, reaches `paperless-db:3306` through `backup-net`, and
resolves credentials by a server-side secret reference. The database principal
is restricted to the documented dump grants.

### Lifecycle and idempotency

Paperless remains running. The action first proves each source root readable
with a separate policy-bound check,
then performs the typed database backup and file backup, and finally probes the
fixed web endpoint. It is **additive** because it creates repository artifacts.
After the first artifact, retries are non-retryable and a crash is `unknown`.
No legacy shell hook runs. E2E cleanup deletes only test-created repository
artifacts; it never deletes Paperless data, MariaDB or broker volumes.
The accepted 302 is not generic: redirects remain disabled and the response
must contain the exact same-origin `Location: /accounts/login/?next=/` header.

## Failure and cleanup semantics

Each machine-readable contract maps start state, parameters, effects, health,
failures and cleanup to a primitive/core operation and an E2E assertion.
Failure injections declare the phase, status, result code, retry class and
permitted residual effects. Additive actions become non-retryable after their
first host effect; a crash after that boundary is `unknown`, never an implicit
rerun. Jellyfin's safe `finally` path is always attempted and cannot replace a
primary backup error.

Test cleanup is identity-bound. File deletion requires a policy alias, exact
relative artifact/run identity, the recorded manifest entry, matching
device/inode, link count one and descriptor-relative symlink-free access. A
mismatch deletes nothing. ACL restoration requires an authenticated pre-test
snapshot and unchanged root identity; otherwise it mutates nothing. Production
cleanup never deletes unmanifested files.

## Security conclusion

The four successful cases use only fixed aliases and the seven planned primitive
families plus existing typed MuckiSafe core backup operations. This inventory
does not claim that the binding primitive schema is frozen: Task 10B must first
incorporate the two required normative strengthenings above. They are
policy-bound checks, not generic execution escape hatches. In particular, the
reference contracts introduce no shell, `eval`, free
executable, free container target, action-supplied absolute path, numeric UID,
redirect-following URL, arbitrary retention glob or unmanifested deletion.
