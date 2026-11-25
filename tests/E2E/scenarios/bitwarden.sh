# shellcheck shell=bash
# Bitwarden scenario of the Linux service E2E (Task 32): the pre phase of
# examples/actions/bitwarden.json against the pinned MSSQL image and the
# seeded bwdata tree of fixtures/bitwarden/fixture.json (policy root
# /opt/bitwarden). Sourced by run-linux-e2e.sh after lib.sh and the step
# scripts, and by the selftest (also on macOS bash 3.2): nothing below runs
# on source except plain assignments.
#
# Bitwarden runs on amd64 only: the pinned mcr.microsoft.com/mssql/server
# image (BITWARDEN_MSSQL_IMAGE) is published for linux/amd64 only, so the
# arm64 run is reported as skipped (decision 2026-11-25).
#
# Start state (contract startState): container bitwarden-mssql running the
# pinned image with the vault database, /backup-db.sh and /backup-db.sql
# (replicas of the bitwarden/mssql image, fixtures/bitwarden/backup-db.*),
# bwdata/mssql/backups mounted at /etc/bitwarden/mssql/backups; the bwdata
# tree is the fixture seed. Host state of this scenario lives in
# $WORK_DIR/bitwarden (env, policy, control, baselines).
#
# Fault injection, one per fixture inject key (agent reference test:
# agent/internal/primitives/reference_bitwarden_test.go):
#   commandExit 1 + artifact none  vault database offline: the real BACKUP
#                                  fails, /backup-db.sh exits 1, no BAK
#   artifact none                  control "artifact=none": exit 0, no BAK
#   artifactBytes N                control "artifact=bytes:N": real BAK,
#                                  truncated to N bytes
#   remove PATH                    PATH removed below the policy root
#   symlink {path, target}         PATH replaced by a symlink to target

: "${SCENARIO_NAME:=bitwarden}"
# Read by the orchestrator, the step scripts and the selftest.
# shellcheck disable=SC2034
{
    SCENARIO_ARCHES="amd64"
    SCENARIO_SUCCESS_CASE="success"
    SCENARIO_DEFAULT_PHASE="pre"
}

readonly BW_CONTAINER=bitwarden-mssql
readonly BW_COMPOSE_PROJECT=muwa-e2e-bitwarden
readonly BW_CONTAINER_BACKUP_DIR=/etc/bitwarden/mssql/backups
# Aliases of examples/policies/linux-host.yaml the Bitwarden action uses.
readonly BW_POLICY_ALIASES="containers.bitwarden-mssql commands.bitwarden-full-backup paths.bitwarden-data principals.muckisafe-backup checks.bitwarden-current-full-backup checks.bitwarden-required-files"

# --- interface: static parts ----------------------------------------------------

scenario_skip_reason() {
    case ${1:-} in
        amd64) return 0 ;;
        *) echo "Bitwarden runs on amd64 only: the pinned mcr.microsoft.com/mssql/server image (BITWARDEN_MSSQL_IMAGE) is published for linux/amd64 only (decision 2026-11-25)" ;;
    esac
}

scenario_cases() {
    fixture_cases
}

scenario_images() {
    echo BITWARDEN_MSSQL_IMAGE
}

scenario_source_mounts() {
    bw_root
}

scenario_backup_paths() {
    echo "$(bw_root)/$(bw_fixture '.core.source')"
}

# One pre hook: the action is additive, so after its first host effect it is
# non-retryable (a restart leaves the job unknown, never a second backup).
scenario_hooks() {
    echo "pre $(bw_fixture '.action') non_retryable 120 true"
}

# --- helpers ----------------------------------------------------------------------

bw_fixture() {
    jq -r "$1" "$(fixture_file)"
}

bw_dir() {
    echo "$WORK_DIR/bitwarden"
}

bw_state() {
    echo "$WORK_DIR/bitwarden/state"
}

# bw_root: the policy root of the fixture (absolute, no traversal).
bw_root() {
    local root
    root=$(bw_fixture '.policyRoot')
    case $root in
        /*/..*|*/../*|*/..|*//*|/) die "unsafe policy root in the fixture: $root" ;;
        /*) echo "$root" ;;
        *) die "policy root must be absolute: $root" ;;
    esac
}

bw_backups_dir() {
    echo "$(bw_root)/$(bw_fixture '.backupCommand.artifact.directory')"
}

# bw_rel PATH: validates a fixture path relative to the policy root.
bw_rel() {
    case $1 in
        ''|/*|..|../*|*/..|*/../*|*//*) die "fixture path must be relative without traversal: '$1'" ;;
    esac
    echo "$1"
}

bw_slug() {
    printf '%s' "$1" | tr -c 'A-Za-z0-9_.-' '_'
}

bw_identity() {
    stat -c '%d:%i' -- "$1"
}

bw_compose() {
    docker compose -p "$BW_COMPOSE_PROJECT" -f "$E2E_DIR/fixtures/bitwarden/compose.yaml" \
        --env-file "$(bw_dir)/bitwarden.env" "$@"
}

bw_running() {
    [ "$(docker inspect -f '{{.State.Running}}' "$BW_CONTAINER" 2>/dev/null)" = true ]
}

bw_assert_running() {
    bw_running || { log "assertion failed: $BW_CONTAINER is not running"; return 1; }
}

# bw_sql QUERY [DATABASE]: sqlcmd as sa inside the container (password from
# the container environment, never in argv); fails on SQL errors.
bw_sql() {
    # shellcheck disable=SC2016
    docker exec "$BW_CONTAINER" bash -c '
        tool=/opt/mssql-tools18/bin/sqlcmd trust=-C
        [ -x "$tool" ] || { tool=/opt/mssql-tools/bin/sqlcmd; trust=; }
        exec "$tool" $trust -S localhost -U sa -b -h -1 -W -d "$1" -Q "$2"' bw-sql "${2:-master}" "$1"
}

bw_mssql_ready() {
    bw_sql "SET NOCOUNT ON; SELECT 1" >/dev/null 2>&1
}

# The vault database of the reference installation with a few fixture rows.
bw_vault_create() {
    bw_sql "IF DB_ID('vault') IS NULL CREATE DATABASE vault" >/dev/null
    bw_sql "SET NOCOUNT ON;
        IF OBJECT_ID('dbo.Cipher') IS NULL
            CREATE TABLE dbo.Cipher (Id UNIQUEIDENTIFIER NOT NULL PRIMARY KEY DEFAULT NEWID(), Data NVARCHAR(MAX) NOT NULL);
        IF NOT EXISTS (SELECT 1 FROM dbo.Cipher)
            INSERT INTO dbo.Cipher (Data) VALUES (N'e2e fixture cipher 1'), (N'e2e fixture cipher 2'), (N'e2e fixture cipher 3');" vault >/dev/null
}

bw_vault_online() {
    bw_sql "IF DATABASEPROPERTYEX('vault', 'Status') <> 'ONLINE' ALTER DATABASE vault SET ONLINE" >/dev/null
}

# No /backup-db.sh (or its sqlcmd) left in the container, e.g. the orphan of
# an agent restart that continues after scenario_stall off.
bw_no_backup_process() {
    local processes
    processes=$(docker top "$BW_CONTAINER" -eo pid,args 2>/dev/null) || return 1
    ! grep -Eq '/backup-db\.sh|sqlcmd' <<<"$processes"
}

# --- control directory (read-only in the container at /run/muwa-e2e) ----------------

bw_control_set() {
    install -d -m 0755 "$(bw_dir)/control"
    printf '%s\n' "$2" >"$(bw_dir)/control/$1.tmp"
    chmod 0644 "$(bw_dir)/control/$1.tmp"
    mv -f "$(bw_dir)/control/$1.tmp" "$(bw_dir)/control/$1"
}

bw_control_clear() {
    rm -f "$(bw_dir)/control/artifact" "$(bw_dir)/control/stall"
}

# --- seed tree ------------------------------------------------------------------------

# bw_seed_tree: creates every missing object of the fixture seed below the
# policy root (parents first) and replaces a symlink standing where the seed
# has a directory or file. Existing objects are left alone; their state is
# what the manifest comparison checks.
bw_seed_tree() {
    local root path mode uid gid content target
    root=$(bw_root)
    if [ -L "$root" ]; then die "$root is a symlink"; fi
    [ -d "$root" ] || install -d -o root -g root -m 0755 "$root"
    while IFS=$'\t' read -r path mode uid gid; do
        target=$root/$(bw_rel "$path")
        if [ -L "$target" ]; then rm -f -- "$target"; fi
        [ -d "$target" ] || install -d -o "$uid" -g "$gid" -m "$mode" "$target"
    done < <(jq -r '.seed.directories[] | [.path, .mode, (.uid // 0), (.gid // 0)] | @tsv' "$(fixture_file)")
    while IFS=$'\t' read -r path mode uid gid content; do
        target=$root/$(bw_rel "$path")
        if [ -L "$target" ]; then rm -f -- "$target"; fi
        if [ -e "$target" ]; then continue; fi
        (umask 077; base64 -d <<<"$content" >"$target")
        chown "$uid:$gid" "$target"
        chmod "$mode" "$target"
    done < <(jq -r '.seed.files[] | [.path, .mode, (.uid // 0), (.gid // 0), (.content | @base64)] | @tsv' "$(fixture_file)")
}

# bw_assert_root_identity: the policy root is still the directory the run
# created (same device/inode), not a replacement or a symlink.
bw_assert_root_identity() {
    bw_root_is_ours ||
        { log "identity mismatch: $(bw_root) is not the seeded policy root; nothing is changed"; return 1; }
}

# bw_root_is_ours: the policy root exists, is no symlink and is the directory
# scenario_prepare_host created (device/inode recorded in root-identity).
bw_root_is_ours() {
    local root marker
    root=$(bw_root)
    marker=$(bw_dir)/root-identity
    [ -f "$marker" ] && [ -d "$root" ] && [ ! -L "$root" ] && [ "$(bw_identity "$root")" = "$(cat "$marker")" ]
}

# bw_backups_listing: "name<TAB>device:inode<TAB>type<TAB>links<TAB>size" of
# every entry of the backup directory.
bw_backups_listing() {
    find "$(bw_backups_dir)" -mindepth 1 -maxdepth 1 -printf '%f\t%D:%i\t%y\t%n\t%s\n' | LC_ALL=C sort
}

# bw_new_artifacts: entries the run created (name or device/inode not in the
# baseline recorded at seed time).
bw_new_artifacts() {
    bw_backups_listing | awk -F'\t' 'NR == FNR { seen[$1 FS $2] = 1; next } !(($1 FS $2) in seen)' \
        "$(bw_state)/backups-baseline.tsv" -
}

# --- interface: service -------------------------------------------------------------

bw_write_env() {
    local password
    password="Bw1!$(od -An -N12 -tx1 /dev/urandom | tr -d ' \n')"
    (
        umask 077
        cat >"$(bw_dir)/bitwarden.env" <<EOF
BITWARDEN_MSSQL_IMAGE=$BITWARDEN_MSSQL_IMAGE
BW_SA_PASSWORD=$password
BW_POLICY_ROOT=$(bw_root)
BW_E2E_DIR=$(bw_dir)
EOF
    )
}

# scenario_prepare_host: before the MuckiSafe stack starts (it mounts the
# policy root read-only and never creates bind sources): the seed tree below
# a policy root this run creates itself; its device/inode is the marker
# every later mutation and the teardown check. An existing root that is not
# ours is never touched.
scenario_prepare_host() {
    local root dir
    root=$(bw_root)
    dir=$(bw_dir)
    if [ -e "$root" ] || [ -L "$root" ]; then
        bw_root_is_ours || die "$root exists and was not created by this E2E run: refusing to touch it"
    fi
    install -d -m 0700 "$dir" "$dir/state"
    bw_seed_tree
    if [ ! -f "$dir/root-identity" ]; then
        bw_identity "$root" >"$dir/root-identity"
    fi
}

scenario_up() {
    local dir
    dir=$(bw_dir)
    bw_root_is_ours || die "$(bw_root) was not created by scenario_prepare_host of this run"
    install -d -m 0755 "$dir/control" "$dir/mssql"
    bw_control_clear
    install -m 0755 "$E2E_DIR/fixtures/bitwarden/backup-db.sh" "$dir/mssql/backup-db.sh"
    install -m 0644 "$E2E_DIR/fixtures/bitwarden/backup-db.sql" "$dir/mssql/backup-db.sql"
    bw_seed_tree
    bw_write_env
    bw_compose pull --quiet
    bw_compose up -d
    wait_for 300 "MSSQL in $BW_CONTAINER accepts connections" bw_mssql_ready
    bw_vault_create
    bw_assert_running
    docker exec "$BW_CONTAINER" test -x /backup-db.sh
}

# scenario_down: removes container and volume; the policy root only when it
# is still the directory this run created.
scenario_down() {
    local root dir
    root=$(bw_root)
    dir=$(bw_dir)
    bw_control_clear
    if [ -f "$dir/bitwarden.env" ]; then
        bw_compose down --volumes --remove-orphans --timeout 30 || true
    fi
    if bw_root_is_ours; then
        rm -rf --one-file-system -- "$root"
    fi
}

scenario_collect_logs() {
    docker logs --timestamps "$BW_CONTAINER" >"$1/bitwarden-mssql.log" 2>&1 || true
    bw_backups_listing >"$1/bitwarden-backups.tsv" 2>&1 || true
}

# --- interface: policy and seed -------------------------------------------------------

# scenario_policy: examples/policies/linux-host.yaml reduced to the aliases
# of the Bitwarden action (entries copied verbatim, including comments).
scenario_policy() {
    local file alias
    file=$(bw_dir)/policy.yaml
    install -d -m 0700 "$(bw_dir)"
    {
        echo "# Generated by tests/E2E/scenarios/bitwarden.sh from $(bw_fixture '.policy')"
        awk -v aliases="$BW_POLICY_ALIASES" '
            BEGIN { n = split(aliases, list, " "); for (i = 1; i <= n; i++) want[list[i]] = 1 }
            /^version:/ { print; next }
            /^#/ || /^[[:space:]]*$/ { next }
            /^[a-z]/ { section = $0; sub(/:.*/, "", section); opened = 0; keep = 0; comments = ""; next }
            /^  #/ { comments = comments $0 "\n"; next }
            /^  [a-z0-9]/ {
                name = $0; sub(/^  /, "", name); sub(/:.*/, "", name)
                keep = ((section "." name) in want)
                if (keep) { if (!opened) { print section ":"; opened = 1 }; printf "%s", comments; print }
                comments = ""
                next
            }
            /^    / { if (keep) print }
        ' "$REPO_ROOT/$(bw_fixture '.policy')"
    } >"$file"
    for alias in $BW_POLICY_ALIASES; do
        grep -q "^  ${alias#*.}:" "$file" || die "policy alias $alias missing in $(bw_fixture '.policy')"
    done
    grep -q "^    root: $(bw_root)\$" "$file" || die "policy root of bitwarden-data is not $(bw_root)"
    echo "$file"
}

# scenario_seed: the fixture seed plus the trusted pre-test baselines
# (manifest, root identity, ACL snapshot, backup directory identities).
scenario_seed() {
    local root state
    root=$(bw_root)
    state=$(bw_state)
    bw_assert_root_identity
    bw_seed_tree
    manifest_save seed "$root"
    (umask 077; getfacl -RPpn "$root" >"$state/acl-seed.facl")
    bw_identity "$(bw_backups_dir)" >"$state/backups-dir-identity"
    { echo '#baseline'; bw_backups_listing; } >"$state/backups-baseline.tsv"
    # The current-run rule compares at second granularity: every seeded
    # object must be older than the first run.
    sleep 2
}

# --- interface: cases ----------------------------------------------------------------

scenario_inject() {
    local name=$1 inject command_exit artifact bytes path target
    inject=$(fixture_inject "$name")
    jq -e 'keys - ["commandExit", "artifact", "artifactBytes", "remove", "symlink"] == []' <<<"$inject" >/dev/null ||
        die "case '$name': unknown inject key in $inject"
    bw_assert_root_identity
    bw_control_clear
    command_exit=$(jq -r '.commandExit // 0' <<<"$inject")
    artifact=$(jq -r '.artifact // ""' <<<"$inject")
    bytes=$(jq -r '.artifactBytes // ""' <<<"$inject")
    if [ "$command_exit" != 0 ]; then
        # The real command fails: BACKUP DATABASE of an offline database
        # errors out, /backup-db.sh exits 1 and creates no BAK.
        [ "$command_exit" = 1 ] && [ "$artifact" = none ] ||
            die "case '$name': only commandExit 1 without an artifact can be produced by the real command"
        bw_sql "ALTER DATABASE vault SET OFFLINE WITH ROLLBACK IMMEDIATE" >/dev/null
    elif [ "$artifact" = none ]; then
        bw_control_set artifact none
    elif [ -n "$artifact" ]; then
        die "case '$name': unknown artifact injection '$artifact'"
    fi
    if [ -n "$bytes" ]; then
        bw_control_set artifact "bytes:$bytes"
    fi
    path=$(jq -r '.remove // ""' <<<"$inject")
    if [ -n "$path" ]; then
        path=$(bw_root)/$(bw_rel "$path")
        [ -e "$path" ] || [ -L "$path" ] || die "case '$name': $path does not exist"
        rm -rf --one-file-system -- "$path"
    fi
    path=$(jq -r '.symlink.path // ""' <<<"$inject")
    if [ -n "$path" ]; then
        target=$(jq -r '.symlink.target' <<<"$inject")
        path=$(bw_root)/$(bw_rel "$path")
        # The link target must stay untouched: fingerprint its ACLs.
        bw_acl_fingerprint "$target" >"$(bw_state)/symlink-target-acl.sha256"
        rm -rf --one-file-system -- "$path"
        ln -s -- "$target" "$path"
    fi
}

# bw_acl_fingerprint PATH: hash of every extended ACL below PATH (symlinks
# are not followed; unreadable objects do not abort the fingerprint).
bw_acl_fingerprint() {
    { getfacl -RPp --skip-base "$1" 2>/dev/null || true; } | sha256sum
}

# bw_as_backup_user COMMAND...: runs as the core's backup principal (UID 33).
bw_as_backup_user() {
    setpriv --reuid="$(bw_fixture '.core.readableBy.uid')" --regid="$(bw_fixture '.core.readableBy.gid')" \
        --clear-groups -- "$@"
}

# bw_assert_artifact LINE BYTES: the run's BAK (BYTES empty: non-empty).
bw_assert_artifact() {
    local line=$1 bytes=$2 name type links size
    IFS=$'\t' read -r name _ type links size <<<"$line"
    [[ $name =~ ^vault_FULL_[0-9]{8}_[0-9]{6}\.BAK$ ]] || { log "assertion failed: unexpected artifact name $name"; return 1; }
    assert_eq "$type" f "artifact type"
    assert_eq "$links" 1 "artifact link count"
    if [ -n "$bytes" ]; then
        assert_eq "$size" "$bytes" "artifact size"
        return 0
    fi
    [ "$size" -gt 0 ] || { log "assertion failed: $name is empty"; return 1; }
    # A real MSSQL backup of the vault database, not just any file.
    assert_contains "$(bw_sql "RESTORE VERIFYONLY FROM DISK = N'$BW_CONTAINER_BACKUP_DIR/$name'")" "is valid" "RESTORE VERIFYONLY of $name"
}

scenario_assert_effects() {
    local name=$1 inject expect new count path
    inject=$(fixture_inject "$name")
    expect=$(fixture_case "$name" | jq -c '.expect')
    bw_assert_running
    # Seeded backups (the stale BAK) are never touched.
    assert_eq "$(bw_backups_listing | awk -F'\t' 'NR == FNR { if ($1 != "#baseline") want[$1 FS $2] = 1; next } ($1 FS $2) in want { n++ } END { print n + 0 }' "$(bw_state)/backups-baseline.tsv" -)" \
        "$(grep -vc '^#' "$(bw_state)/backups-baseline.tsv")" "seeded backups unchanged"
    new=$(bw_new_artifacts)
    count=$(grep -c . <<<"$new" || true)
    if jq -e '.artifact == "none"' <<<"$inject" >/dev/null; then
        assert_eq "$count" 0 "BAK files created by the run"
    else
        assert_eq "$count" 1 "BAK files created by the run"
        bw_assert_artifact "$new" "$(jq -r '.artifactBytes // ""' <<<"$inject")"
    fi
    if jq -e '(.commandExit // 0) != 0' <<<"$inject" >/dev/null; then
        # The pre phase stopped at step 1: no ACL or any other change.
        manifest_save "after-$(bw_slug "$name")" "$(bw_root)"
        manifest_equal seed "after-$(bw_slug "$name")"
    fi
    path=$(jq -r '.remove // ""' <<<"$inject")
    if [ -n "$path" ]; then
        [ ! -e "$(bw_root)/$path" ] && [ ! -L "$(bw_root)/$path" ] ||
            { log "assertion failed: $path was recreated"; return 1; }
    fi
    path=$(jq -r '.symlink.path // ""' <<<"$inject")
    if [ -n "$path" ]; then
        assert_eq "$(readlink -- "$(bw_root)/$path")" "$(jq -r '.symlink.target' <<<"$inject")" "symlink kept"
        assert_eq "$(bw_acl_fingerprint "$(jq -r '.symlink.target' <<<"$inject")")" \
            "$(cat "$(bw_state)/symlink-target-acl.sha256")" "ACLs of the symlink target untouched"
    fi
    if jq -e '.core == "readable"' <<<"$expect" >/dev/null; then
        # The whole source tree, the BAK and the restore-critical files are
        # readable as the backup principal; the BAK carries the read ACL.
        bw_as_backup_user find "$(bw_root)/$(bw_fixture '.core.source')" -type f -exec cat -- {} + >/dev/null
        bw_assert_snapshot "$(bw_backups_dir)/$(cut -f1 <<<"$new")"
        assert_contains "$(getfacl -cpn "$(bw_backups_dir)/$(cut -f1 <<<"$new")")" \
            "user:$(bw_fixture '.core.readableBy.uid'):r" "read ACL on the BAK"
    fi
}

# bw_assert_snapshot BAK: the latest restic snapshot of the scenario
# repository holds every seeded file of the core source (attachments
# included) and the run's BAK.
bw_assert_snapshot() {
    local listing path
    listing=$(restic_in_app ls latest)
    while IFS= read -r path; do
        grep -qxF -- "$path" <<<"$listing" || { log "assertion failed: $path is not in the latest snapshot"; return 1; }
    done < <(jq -r --arg root "$(bw_root)" --arg source "$(bw_fixture '.core.source')" \
        '.seed.files[].path | select(startswith($source + "/")) | $root + "/" + .' "$(fixture_file)"; echo "$1")
}

# bw_cleanup_artifacts: deletes only the run's BAK files, recorded with
# device/inode first; every one must be a regular file with one link in the
# unchanged backup directory of the unchanged root, else nothing is deleted.
bw_cleanup_artifacts() {
    local dir list name identity type links size
    bw_assert_root_identity
    dir=$(bw_backups_dir)
    [ -d "$dir" ] && [ ! -L "$dir" ] && [ "$(bw_identity "$dir")" = "$(cat "$(bw_state)/backups-dir-identity")" ] ||
        { log "identity mismatch: $dir was replaced; nothing is deleted"; return 1; }
    list=$(bw_state)/artifacts-$(date +%s).tsv
    bw_new_artifacts >"$list"
    while IFS=$'\t' read -r name identity type links size; do
        [ "$type" = f ] && [ "$links" = 1 ] && [ -n "$name" ] && [[ $name != */* ]] ||
            { log "identity mismatch: $name ($type, $links links) is no run artifact; nothing is deleted"; return 1; }
    done <"$list"
    while IFS=$'\t' read -r name identity type links size; do
        [ "$(find "$dir/$name" -maxdepth 0 -printf '%D:%i\t%y\t%n')" = "$identity	f	1" ] ||
            { log "identity mismatch: $name changed before deletion; it is kept"; return 1; }
        rm -f -- "${dir:?}/$name"
        log "removed run artifact $name ($identity, $size bytes)"
    done <"$list"
}

# bw_restore_acls: the trusted pre-test ACL snapshot back onto the tree, only
# while the root is the seeded one and the tree holds no symlink.
bw_restore_acls() {
    local root snapshot
    root=$(bw_root)
    snapshot=$(bw_state)/acl-seed.facl
    bw_assert_root_identity
    [ "$(stat -c '%u %a' "$snapshot")" = "0 600" ] || { log "ACL snapshot is not trusted; nothing is changed"; return 1; }
    [ -z "$(find "$root" -type l -print -quit)" ] || { log "symlink below $root; ACLs are not changed"; return 1; }
    setfacl -R -P -b "$root"
    setfacl --restore="$snapshot"
}

# scenario_reset: undo the injection, test-only cleanup of the fixture
# (identity-bound artifact deletion, ACL restore) and prove the end state.
scenario_reset() {
    local name=$1 end
    bw_control_clear
    wait_for 180 "no backup command left in $BW_CONTAINER" bw_no_backup_process
    bw_assert_running
    bw_vault_online
    bw_assert_root_identity
    bw_seed_tree
    bw_cleanup_artifacts
    bw_restore_acls
    end="end-$(bw_slug "$name")"
    manifest_save "$end" "$(bw_root)"
    manifest_equal seed "$end"
}

# scenario_stall on|off: /backup-db.sh — the first host effect of the pre
# phase — waits while the control file exists (bounded to 600 s).
scenario_stall() {
    case ${1:-} in
        on) bw_control_set stall on ;;
        off) rm -f "$(bw_dir)/control/stall" ;;
        *) die "scenario_stall on|off" ;;
    esac
}
