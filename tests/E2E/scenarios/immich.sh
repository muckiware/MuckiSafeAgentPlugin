# shellcheck shell=bash
# Immich scenario of the Linux service E2E (Task 32). Implements the scenario
# interface of tests/E2E/README.md against the pinned Immich stack
# (tests/E2E/fixtures/immich/compose.yaml: server, machine learning, Valkey,
# immich_postgres) and replays every case of fixtures/immich/fixture.json the
# way agent/internal/primitives/reference_immich_test.go does with fakes.
#
# Hook: pre = examples/actions/immich.json (write pipeline pg_dump | gzip into
# UPLOAD_LOCATION/backups, manifest-bound retention, read ACLs, readability
# checks). The action is additive: non_retryable after its first host effect.
#
# Fixture tree: $WORK_DIR/immich holds the fixture "seed" exactly as the
# reference test's temporary base; the policy roots /opt/immich/library/... of
# examples/policies/linux-host.yaml are replaced by the layout's directories
# below it (the reference test does the same). Layout "default" keeps the
# derivative locations inside UPLOAD_LOCATION, "separate" points THUMB_,
# ENCODED_VIDEO_ and PROFILE_LOCATION to external/*. A layout is one
# operator configuration: policy + documented install.sh rerun (writable
# paths), Immich server with the same locations, Facility backup paths.
#
# The ten earlier manual dumps are written and recorded in the agent's
# root-protected manifest (state/manifests/immich-manual-backups.json, under
# the manifest directory's flock) like earlier write-pipeline runs, exactly as
# primeHistory of the reference test.
#
# Injections (fixture "inject"):
#   replaceHistory N        dump N replaced by another file (same name, owner,
#                           mode): manifest identity mismatch
#   remove PATH             PATH removed (rm -rf)
#   symlink {path,target}   PATH replaced by a symlink to target
#   commandExit 1 +         the real pg_dump is terminated by the server
#   output "partial"        (pg_terminate_backend) while it streams a large
#                           e2e table: exit 1 after partial output
#   output "none"           pg_dump in immich_postgres is shadowed by a wrapper
#                           that runs the real pg_dump and discards its output:
#                           exit 0 without output
#
# Sourced by run-linux-e2e.sh and the selftest (also by macOS bash 3.2): the
# top level only assigns variables and defines functions.

# The selftest sources the scenario without the orchestrator's SCENARIO_NAME.
: "${SCENARIO_NAME:=immich}"
# Read by the orchestrator, the step scripts and the selftest.
# shellcheck disable=SC2034
{
    SCENARIO_ARCHES="amd64 arm64"
    SCENARIO_SUCCESS_CASE="success in the default layout"
    SCENARIO_DEFAULT_PHASE=pre
}

IMMICH_PROJECT=muwa-e2e-immich
IMMICH_BASE=$WORK_DIR/immich
IMMICH_STATE=$WORK_DIR/immich-state
IMMICH_CONTAINERS="immich_server immich_machine_learning immich_redis immich_postgres"
IMMICH_PATH_ALIASES="immich-upload immich-thumbs immich-encoded-video immich-profile"
IMMICH_DESTINATION=immich-manual-backups
IMMICH_DUMP_DIR=library/backups
IMMICH_HISTORY_CONTENT="earlier manual dump"
IMMICH_PARTIAL_TABLE=public.e2e_partial_output

# --- interface: static parts ------------------------------------------------------

scenario_images() {
    cat <<'EOF'
IMMICH_SERVER_IMAGE
IMMICH_MACHINE_LEARNING_IMAGE
IMMICH_POSTGRES_IMAGE
IMMICH_VALKEY_IMAGE
EOF
}

# The common parent of both layouts, never removed while the stack runs: a
# bind mount of a root that a case removes and recreates would keep showing
# the old directory.
scenario_source_mounts() {
    echo "$IMMICH_BASE"
}

# The fixture's core sources in the current layout (outermost only).
scenario_backup_paths() {
    immich_layout_roots "$(immich_layout)" | immich_outermost
}

scenario_cases() {
    fixture_cases
}

scenario_hooks() {
    cat <<'EOF'
pre examples/actions/immich.json non_retryable 90 true
EOF
}

scenario_policy() {
    immich_write_policy "$(immich_layout)"
}

# --- layout and paths -------------------------------------------------------------

immich_layout() {
    cat "$IMMICH_STATE/layout" 2>/dev/null || echo default
}

# immich_example_root ALIAS: the root of a path alias in the example policy.
immich_example_root() {
    local root
    root=$(awk -v alias="  $1:" '$0 == alias { found = 1; next } found && $1 == "root:" { print $2; exit }' \
        "$REPO_ROOT/examples/policies/linux-host.yaml")
    [ -n "$root" ] || die "examples/policies/linux-host.yaml defines no root for $1"
    echo "$root"
}

# immich_root LAYOUT ALIAS: the host directory of a path alias in a layout.
immich_root() {
    local relative
    relative=$(jq -er --arg layout "$1" --arg root "$(immich_example_root "$2")" \
        '.layouts[$layout][$root]' "$(fixture_file)") || die "fixture layout $1 does not map $2"
    echo "$IMMICH_BASE/$relative"
}

immich_layout_roots() {
    local alias
    for alias in $IMMICH_PATH_ALIASES; do
        immich_root "$1" "$alias"
    done
}

# immich_outermost: sorted paths without the ones below another listed path.
immich_outermost() {
    LC_ALL=C sort -u | awk '{ for (i = 1; i <= n; i++) if ($0 == kept[i] || index($0, kept[i] "/") == 1) next; kept[++n] = $0; print }'
}

# immich_root_identities LAYOUT: device:inode of every root (bind mounts of
# the agent unit and the Immich server refer to these inodes).
immich_root_identities() {
    local root identities=""
    while IFS= read -r root; do
        identities="$identities $(stat -c '%d:%i' "$root" 2>/dev/null || echo missing)"
    done < <(immich_layout_roots "$1")
    echo "$1$identities"
}

# immich_in_snapshot TSV PATH: PATH is listed in a saved tree manifest (awk,
# not cut | grep -q: an early grep exit would fail the pipe under pipefail).
immich_in_snapshot() {
    awk -F'\t' -v path="$2" '$1 == path { found = 1 } END { exit !found }' "$1"
}

immich_dump_dir() {
    echo "$IMMICH_BASE/$IMMICH_DUMP_DIR"
}

# immich_history_names: names of the ten earlier dumps, oldest first.
immich_history_names() {
    jq -r '.history as $h | .dump as $d | range(0; $h.runs) as $i
        | $d.prefix + $h.runIdPrefix + (if $i < 10 then "0" else "" end) + ($i | tostring) + $d.suffix' "$(fixture_file)"
}

immich_history_run_ids() {
    jq -r '.history as $h | range(0; $h.runs) as $i | $h.runIdPrefix + (if $i < 10 then "0" else "" end) + ($i | tostring)' "$(fixture_file)"
}

# immich_run_id: the run id of the write pipeline (the pre job id, dashed).
immich_run_id() {
    local hex
    hex=$(job_of pre | jq -r '.id // empty')
    [ -n "$hex" ] || return 1
    sed -E 's/^(.{8})(.{4})(.{4})(.{4})(.{12})$/\1-\2-\3-\4-\5/' <<<"$hex"
}

immich_dump_name() {
    jq -r --arg run "$1" '.dump.prefix + $run + .dump.suffix' "$(fixture_file)"
}

# --- policy -----------------------------------------------------------------------

# immich_write_policy LAYOUT: examples/policies/linux-host.yaml reduced to the
# Immich aliases, path roots from the fixture layout; prints its path.
immich_write_policy() {
    local layout=$1 file=$IMMICH_STATE/policy.yaml fixture alias
    fixture=$(fixture_file)
    install -d -o root -g root -m 0700 "$IMMICH_STATE"
    {
        cat <<EOF
# Generated by tests/E2E/scenarios/immich.sh from
# examples/policies/linux-host.yaml: only the Immich aliases, path roots of
# the fixture layout "$layout".
version: 1

containers:
  immich-postgres:
    name: $(jq -r '.dump.container' "$fixture")

commands:
  immich-pg-dump:
    containerAlias: immich-postgres
    argv: [$(jq -r '.dump.argv | join(", ")' "$fixture")]
    timeoutSeconds: 600

paths:
EOF
        for alias in $IMMICH_PATH_ALIASES; do
            printf '  %s:\n    root: %s\n    recursive: true\n' "$alias" "$(immich_root "$layout" "$alias")"
        done
        cat <<EOF

destinations:
  $IMMICH_DESTINATION:
    pathAlias: immich-upload
    directory: backups
    prefix: $(jq -r '.dump.prefix' "$fixture")
    suffix: $(jq -r '.dump.suffix' "$fixture")
    owner: $(jq -r '.dump.uid' "$fixture")
    group: $(jq -r '.dump.gid' "$fixture")
    mode: "$(jq -r '.dump.mode' "$fixture")"
    maximumBytes: 21474836480
    maximumKeep: 10

principals:
  muckisafe-backup:
    uid: $(jq -r '.core.readableBy.uid' "$fixture")
    gid: $(jq -r '.core.readableBy.gid' "$fixture")

checks:
  immich-upload-required-readable:
    pathAlias: immich-upload
    readableBy: muckisafe-backup
    recursive: true
  immich-thumbs-required-readable:
    pathAlias: immich-thumbs
    readableBy: muckisafe-backup
    recursive: true
  immich-encoded-video-required-readable:
    pathAlias: immich-encoded-video
    readableBy: muckisafe-backup
    recursive: true
  immich-profile-required-readable:
    pathAlias: immich-profile
    readableBy: muckisafe-backup
    recursive: true
  immich-current-run-output:
    destinationAlias: $IMMICH_DESTINATION
    readableBy: muckisafe-backup
    minimumSize: 1
EOF
    } >"$file"
    chmod 0644 "$file"
    echo "$file"
}

# --- Immich stack -----------------------------------------------------------------

immich_compose() {
    local layout
    layout=$(immich_layout)
    UPLOAD_LOCATION=$(immich_root "$layout" immich-upload) \
    THUMB_LOCATION=$(immich_root "$layout" immich-thumbs) \
    ENCODED_VIDEO_LOCATION=$(immich_root "$layout" immich-encoded-video) \
    PROFILE_LOCATION=$(immich_root "$layout" immich-profile) \
    DB_PASSWORD=$(cat "$IMMICH_STATE/db-password" 2>/dev/null || echo teardown) \
    IMMICH_CONFIG_LOCATION=$IMMICH_STATE/immich-config.json \
        docker compose -p "$IMMICH_PROJECT" -f "$E2E_DIR/fixtures/immich/compose.yaml" "$@"
}

# immich_psql SQL: one statement in the immich database (unaligned, tuples only).
immich_psql() {
    docker exec immich_postgres psql -X -q -v ON_ERROR_STOP=1 -At -U postgres -d immich -c "$1"
}

immich_postgres_running() {
    [ "$(docker inspect -f '{{.State.Running}}' immich_postgres 2>/dev/null)" = true ]
}

# immich_version_ok: the contract's version endpoint answers 200 with the
# reference version.
immich_version_ok() {
    local url major minor patch
    url=$(jq -r '.aliases.endpoints["immich-version"].url' "$E2E_DIR/contracts/immich.json")
    IFS=. read -r major minor patch <<<"$IMMICH_REFERENCE_VERSION"
    curl -fsS --max-time 10 "$url" |
        jq -e --argjson a "$major" --argjson b "$minor" --argjson c "$patch" \
            '.major == $a and .minor == $b and .patch == $c' >/dev/null
}

# Immich writes a .immich marker into every storage folder and checks it on
# start. They are Immich-managed state: kept by the fixture reset and, like
# an operator who moves a location, copied into the separate locations.
immich_seed_dirs() {
    jq -r '.seed.directories[].path' "$(fixture_file)"
}

immich_save_markers() {
    local relative folder
    while IFS= read -r relative; do
        if [ -f "$IMMICH_BASE/$relative/.immich" ] && [ ! -L "$IMMICH_BASE/$relative/.immich" ]; then
            mkdir -p "$IMMICH_STATE/markers/$relative"
            cp -p "$IMMICH_BASE/$relative/.immich" "$IMMICH_STATE/markers/$relative/.immich"
        fi
    done < <(immich_seed_dirs)
    for folder in thumbs encoded-video profile; do
        if [ -f "$IMMICH_STATE/markers/library/$folder/.immich" ] && [ ! -f "$IMMICH_STATE/markers/external/$folder/.immich" ]; then
            mkdir -p "$IMMICH_STATE/markers/external/$folder"
            cp -p "$IMMICH_STATE/markers/library/$folder/.immich" "$IMMICH_STATE/markers/external/$folder/.immich"
        fi
    done
}

immich_restore_markers() {
    local relative target
    while IFS= read -r relative; do
        [ -f "$IMMICH_STATE/markers/$relative/.immich" ] || continue
        target=$IMMICH_BASE/$relative/.immich
        if [ -L "$target" ] || { [ -e "$target" ] && [ ! -f "$target" ]; }; then
            rm -rf -- "$target"
        fi
        [ -f "$target" ] || cp -p "$IMMICH_STATE/markers/$relative/.immich" "$target"
    done < <(immich_seed_dirs)
}

immich_server_recreate() {
    immich_compose up -d --no-deps --force-recreate --wait --wait-timeout 300 immich-server
    wait_for 300 "Immich version endpoint" immich_version_ok
    immich_save_markers
}

# --- agent and Facility per layout ------------------------------------------------

# immich_agent_reinstall: the documented operator flow after changing path
# roots: install the policy, rerun install.sh (writable paths of the unit,
# restart).
immich_agent_reinstall() {
    local package binary
    package=$(cat "$WORK_DIR/agent-package-dir")
    binary=$(cat "$WORK_DIR/agent-binary")
    install -o root -g root -m 0644 "$(scenario_policy)" "$AGENT_POLICY"
    /usr/local/bin/mucki-agent config validate --config "$AGENT_CONFIG"
    agent_mark_restart
    "$package/install.sh" --binary "$binary" --container "$(stack ps -q app)" --container "$(stack ps -q scheduler)"
    wait_for 60 "agent host active" agent_active
}

# immich_apply_layout LAYOUT: agent policy/unit, Immich server locations and
# Facility backup paths follow the layout; agent and server are restarted
# only when the layout or the identity of a root changed.
immich_apply_layout() {
    local layout=$1 wanted repository
    echo "$layout" >"$IMMICH_STATE/layout"
    wanted=$(immich_root_identities "$layout")
    if [ "$(cat "$IMMICH_STATE/agent-applied" 2>/dev/null)" != "$wanted" ]; then
        log "immich: agent policy for layout $layout"
        immich_agent_reinstall
        echo "$wanted" >"$IMMICH_STATE/agent-applied"
    fi
    if [ "$(cat "$IMMICH_STATE/server-applied" 2>/dev/null)" != "$wanted" ]; then
        log "immich: server locations for layout $layout"
        immich_server_recreate
        echo "$wanted" >"$IMMICH_STATE/server-applied"
    fi
    if [ -f "$WORK_DIR/repository-id" ] && [ "$(cat "$IMMICH_STATE/facility-applied" 2>/dev/null)" != "$layout" ]; then
        repository=$(cat "$WORK_DIR/repository-id")
        api PATCH "/api/muwa-backup-repository/$repository" \
            "$(jq -nc --argjson paths "$(backup_paths_json)" '{backupPaths: $paths}')" >/dev/null
        echo "$layout" >"$IMMICH_STATE/facility-applied"
    fi
}

# --- fixture tree -------------------------------------------------------------------

immich_allowed_paths() {
    local fixture name
    fixture=$(fixture_file)
    jq -r '.seed.directories[].path, .seed.files[].path, (.seed.directories[].path + "/.immich")' "$fixture"
    while IFS= read -r name; do
        echo "$IMMICH_DUMP_DIR/$name"
    done < <(immich_history_names)
}

# immich_converge: the tree becomes the fixture seed plus the ten earlier
# dumps, without extended ACLs. Existing seed directories keep their inode
# (bind mounts of the agent unit and the Immich server refer to them).
immich_converge() {
    local fixture base=$IMMICH_BASE relative mode uid gid content name dump_dir
    fixture=$(fixture_file)
    dump_dir=$(immich_dump_dir)
    install -d -o root -g root -m 0755 "$base"
    while IFS=$'\t' read -r relative mode; do
        if [ -L "$base/$relative" ] || { [ -e "$base/$relative" ] && [ ! -d "$base/$relative" ]; }; then
            rm -rf -- "${base:?}/$relative"
        fi
        [ -d "$base/$relative" ] || mkdir -- "$base/$relative"
    done < <(jq -r '.seed.directories[] | [.path, .mode] | @tsv' "$fixture")
    immich_allowed_paths | LC_ALL=C sort -u >"$IMMICH_STATE/allowed-paths"
    (cd "$base" && find . -mindepth 1 -printf '%P\n') | LC_ALL=C sort -u |
        LC_ALL=C comm -23 - "$IMMICH_STATE/allowed-paths" | while IFS= read -r relative; do
            rm -rf -- "${base:?}/$relative"
        done
    while IFS=$'\t' read -r relative mode uid gid content; do
        if [ -L "$base/$relative" ] || { [ -e "$base/$relative" ] && [ ! -f "$base/$relative" ]; }; then
            rm -rf -- "${base:?}/$relative"
        fi
        printf '%s' "$content" >"$base/$relative"
    done < <(jq -r '.seed.files[] | [.path, .mode, (.uid // 0), (.gid // 0), .content] | @tsv' "$fixture")
    immich_restore_markers
    while IFS= read -r name; do
        rm -f -- "$dump_dir/$name"
        printf '%s' "$IMMICH_HISTORY_CONTENT" >"$dump_dir/$name"
    done < <(immich_history_names)
    find "$base" ! -type l -exec setfacl -b {} +
    chmod 0755 "$base"
    while IFS=$'\t' read -r relative mode uid gid; do
        chown "$uid:$gid" "$base/$relative"
        chmod "$mode" "$base/$relative"
    done < <(jq -r '.seed.directories[] | [.path, .mode, (.uid // 0), (.gid // 0)] | @tsv' "$fixture")
    while IFS=$'\t' read -r relative mode uid gid; do
        chown "$uid:$gid" "$base/$relative"
        chmod "$mode" "$base/$relative"
    done < <(jq -r '.seed.files[] | [.path, .mode, (.uid // 0), (.gid // 0)] | @tsv' "$fixture")
    while IFS= read -r name; do
        chown "$(jq -r '.history | "\(.uid):\(.gid)"' "$fixture")" "$dump_dir/$name"
        chmod "$(jq -r '.history.mode' "$fixture")" "$dump_dir/$name"
    done < <(immich_history_names)
}

# --- agent manifest -----------------------------------------------------------------

immich_manifest_file() {
    echo "$AGENT_STATE/manifests/$IMMICH_DESTINATION.json"
}

immich_manifest_json() {
    cat "$(immich_manifest_file)" 2>/dev/null || echo '{"entries": []}'
}

# immich_manifest_store JSON: replaces the manifest like the agent does
# (0600 temporary, rename) under the exclusive flock of its directory.
immich_manifest_store() {
    local dir=$AGENT_STATE/manifests
    install -d -o root -g root -m 0700 "$dir"
    # shellcheck disable=SC2016 # $1 belongs to the inner shell
    flock -x "$dir" sh -c 'umask 077 && cat >"$1.e2e.tmp" && chmod 0600 "$1.e2e.tmp" && mv -f "$1.e2e.tmp" "$1"' \
        sh "$(immich_manifest_file)" <<<"$1"
}

# immich_prime_history: the ten earlier dumps as earlier write-pipeline runs
# recorded them (primeHistory of the reference test).
immich_prime_history() {
    local dump_dir entries="[]" run name identity sequence=1
    dump_dir=$(immich_dump_dir)
    while IFS=$'\t' read -r run name; do
        identity=$(stat -c '%d %i' "$dump_dir/$name")
        entries=$(jq -c --arg name "$name" --arg run "$run" --argjson dev "${identity% *}" --argjson ino "${identity#* }" \
            --argjson seq "$sequence" '. + [{name: $name, device: $dev, inode: $ino, runId: $run, sequence: $seq}]' <<<"$entries")
        sequence=$((sequence + 1))
    done < <(paste <(immich_history_run_ids) <(immich_history_names))
    immich_manifest_store "$(jq -c --arg dest "$IMMICH_DESTINATION" --argjson next "$sequence" \
        '{version: 1, destination: $dest, next: $next, entries: .}' <<<"$entries")"
}

# --- injections ---------------------------------------------------------------------

immich_replace_history() {
    local path owner mode
    path=$(immich_dump_dir)/$(immich_history_names | sed -n "$(($1 + 1))p")
    [ -f "$path" ] || die "history dump $1 does not exist"
    owner=$(stat -c '%u:%g' "$path")
    mode=$(stat -c '%a' "$path")
    printf 'not the recorded dump' >"$path.other"
    chown "$owner" "$path.other"
    chmod "$mode" "$path.other"
    mv -f "$path.other" "$path"
}

# The partial dump: a large table whose COPY is still streaming when the
# watcher terminates pg_dump's backend; pg_dump exits 1 after the schema and
# the data written so far.
immich_partial_watch() {
    local deadline=$((SECONDS + 600)) result
    while [ "$SECONDS" -lt "$deadline" ]; do
        result=$(immich_psql "SELECT pg_terminate_backend(pid) FROM pg_stat_activity
            WHERE application_name = 'pg_dump' AND query LIKE 'COPY $IMMICH_PARTIAL_TABLE %'" 2>/dev/null || true)
        if grep -qx t <<<"$result"; then
            echo terminated >"$IMMICH_STATE/partial-terminated"
            return 0
        fi
        sleep 0.2
    done
    return 1
}

immich_partial_start() {
    immich_psql "DROP TABLE IF EXISTS $IMMICH_PARTIAL_TABLE"
    immich_psql "CREATE TABLE $IMMICH_PARTIAL_TABLE (id integer PRIMARY KEY, payload text NOT NULL)"
    immich_psql "INSERT INTO $IMMICH_PARTIAL_TABLE SELECT g, repeat(md5(g::text), 128) FROM generate_series(1, 300000) AS g"
    rm -f "$IMMICH_STATE/partial-terminated"
    immich_partial_watch </dev/null >"$IMMICH_STATE/partial-watch.log" 2>&1 &
    echo $! >"$IMMICH_STATE/partial-watch.pid"
}

immich_partial_stop() {
    if [ -f "$IMMICH_STATE/partial-watch.pid" ]; then
        kill "$(cat "$IMMICH_STATE/partial-watch.pid")" 2>/dev/null || true
        rm -f "$IMMICH_STATE/partial-watch.pid"
    fi
    if immich_postgres_running; then
        immich_psql "DROP TABLE IF EXISTS $IMMICH_PARTIAL_TABLE" >/dev/null
    fi
}

# The empty dump: pg_dump resolves to a wrapper that runs the real pg_dump
# (the invocation stays visible in the server log) and discards its output.
immich_pg_dump_shadow() {
    local resolved real
    resolved=$(docker exec immich_postgres sh -c 'command -v pg_dump')
    case $resolved in
        /*) ;;
        *) die "pg_dump is not an executable path in immich_postgres: '$resolved'" ;;
    esac
    if docker exec immich_postgres test -L "$resolved"; then
        real=$(docker exec immich_postgres readlink -f "$resolved")
        [ "$(basename -- "$real")" = pg_dump ] || die "pg_dump in immich_postgres dispatches by name ($real)"
    else
        real=$resolved.e2e-real
    fi
    echo "$resolved" >"$IMMICH_STATE/pg-dump-shadow"
    docker exec immich_postgres mv "$resolved" "$resolved.e2e-real"
    # shellcheck disable=SC2016 # "$@" and "$?" belong to the wrapper
    printf '#!/bin/sh\n# E2E injection "empty dump" (Task 32): the real pg_dump runs, its output is discarded.\n"%s" "$@" >/dev/null || exit "$?"\nexit 0\n' "$real" |
        docker exec -i immich_postgres sh -c 'cat >"$1" && chmod 0755 "$1"' sh "$resolved"
}

immich_pg_dump_restore() {
    local resolved
    [ -f "$IMMICH_STATE/pg-dump-shadow" ] || return 0
    resolved=$(cat "$IMMICH_STATE/pg-dump-shadow")
    if docker exec immich_postgres test -e "$resolved.e2e-real" || docker exec immich_postgres test -L "$resolved.e2e-real"; then
        docker exec immich_postgres mv -f "$resolved.e2e-real" "$resolved"
    fi
    assert_eq "$(docker exec immich_postgres sh -c 'command -v pg_dump')" "$resolved" "pg_dump in immich_postgres"
    if docker exec immich_postgres grep -q 'E2E injection' "$resolved"; then
        die "the pg_dump wrapper is still installed in immich_postgres"
    fi
    rm -f "$IMMICH_STATE/pg-dump-shadow"
}

# immich_undo_service_injections: everything an injection changed outside
# the fixture tree; idempotent, also after an aborted case.
immich_undo_service_injections() {
    scenario_stall off
    immich_partial_stop
    if immich_postgres_running; then
        immich_pg_dump_restore
    fi
}

# immich_apply_inject CASE: the case's inject on the prepared tree/service.
immich_apply_inject() {
    local inject replace remove link target output command_exit
    inject=$(fixture_inject "$1")
    replace=$(jq -r '.replaceHistory // empty' <<<"$inject")
    remove=$(jq -r '.remove // empty' <<<"$inject")
    link=$(jq -r '.symlink.path // empty' <<<"$inject")
    target=$(jq -r '.symlink.target // empty' <<<"$inject")
    output=$(jq -r '.output // empty' <<<"$inject")
    command_exit=$(jq -r '.commandExit // 0' <<<"$inject")
    if [ -n "$replace" ]; then
        immich_replace_history "$replace"
    fi
    if [ -n "$remove" ]; then
        [ -e "$IMMICH_BASE/$remove" ] || [ -L "$IMMICH_BASE/$remove" ] || die "inject.remove: $remove does not exist"
        rm -rf -- "${IMMICH_BASE:?}/$remove"
    fi
    if [ -n "$link" ]; then
        rm -rf -- "${IMMICH_BASE:?}/$link"
        ln -s -- "$target" "$IMMICH_BASE/$link"
    fi
    case "$command_exit/$output" in
        0/) ;;
        1/partial) immich_partial_start ;;
        0/none) immich_pg_dump_shadow ;;
        *) die "unsupported dump injection commandExit=$command_exit output=$output" ;;
    esac
}

# --- per-run record -------------------------------------------------------------------

immich_start_manifest() {
    echo "$OUT_DIR/manifests/immich-$(cat "$IMMICH_STATE/run-seq").start.tsv"
}

# immich_record_start CASE: the trusted pre-test snapshot of the run (tree
# with modes and ACLs, root identity, log mark) taken after the injection,
# as the reference test's snapshotTree.
immich_record_start() {
    local sequence target
    sequence=$(printf '%02d' $((10#$(cat "$IMMICH_STATE/run-seq" 2>/dev/null || echo 0) + 1)))
    echo "$sequence" >"$IMMICH_STATE/run-seq"
    echo "$1" >"$IMMICH_STATE/run-case"
    mkdir -p "$OUT_DIR/manifests"
    echo "$1" >"$OUT_DIR/manifests/immich-$sequence.case.txt"
    manifest_save "immich-$sequence.start" "$IMMICH_BASE"
    sha256sum "$(immich_start_manifest)" | cut -c1-64 >"$IMMICH_STATE/start-sha256"
    stat -c '%d:%i' "$IMMICH_BASE" "$IMMICH_BASE/library" >"$IMMICH_STATE/root-identity"
    target=$(fixture_inject "$1" | jq -r '.symlink.target // empty')
    if [ -n "$target" ]; then
        getfacl -cpn "$target" >"$IMMICH_STATE/target-acl" 2>&1
    else
        rm -f "$IMMICH_STATE/target-acl"
    fi
    date -u +%Y-%m-%dT%H:%M:%S.%NZ >"$IMMICH_STATE/log-mark"
}

# immich_prepare CASE: fixture tree, layout, history and inject of one run.
immich_prepare() {
    local layout
    layout=$(fixture_case "$1" | jq -r '.layout // "default"')
    immich_undo_service_injections
    immich_converge
    immich_apply_layout "$layout"
    immich_prime_history
    immich_apply_inject "$1"
    immich_record_start "$1"
}

# --- interface: lifecycle ---------------------------------------------------------------

# Before the MuckiSafe stack starts: the source mount (parent of both
# layouts) must exist, the stack never creates bind sources.
scenario_prepare_host() {
    install -d -o root -g root -m 0755 "$IMMICH_BASE"
}

scenario_up() {
    local tool
    for tool in flock setpriv gzip setfacl getfacl stat paste; do
        command -v "$tool" >/dev/null || die "the immich scenario needs $tool"
    done
    install -d -o root -g root -m 0700 "$IMMICH_STATE"
    [ -s "$IMMICH_STATE/db-password" ] || (umask 077 && random_hex 16 >"$IMMICH_STATE/db-password")
    install -o root -g root -m 0644 "$E2E_DIR/fixtures/immich/immich-config.json" "$IMMICH_STATE/immich-config.json"
    echo default >"$IMMICH_STATE/layout"
    immich_converge
    immich_compose up -d --wait --wait-timeout 600
    wait_for 300 "Immich version endpoint" immich_version_ok
    # Start state: immich_postgres accepts pg_dump; every pg_dump connection
    # is logged (evidence of "invoked").
    immich_psql "ALTER SYSTEM SET log_connections = on" >/dev/null
    immich_psql "SELECT pg_reload_conf()" >/dev/null
    docker exec immich_postgres pg_dump --schema-only --dbname=immich --username=postgres >/dev/null
    immich_save_markers
    immich_converge
    immich_root_identities default >"$IMMICH_STATE/server-applied"
}

scenario_down() {
    scenario_stall off || true
    if [ -f "$E2E_DIR/fixtures/immich/compose.yaml" ]; then
        immich_compose down --volumes --remove-orphans --timeout 30 || true
    fi
    docker ps -aq --filter "label=com.docker.compose.project=$IMMICH_PROJECT" | xargs -r docker rm -f >/dev/null 2>&1 || true
    docker volume ls -q --filter "label=com.docker.compose.project=$IMMICH_PROJECT" | xargs -r docker volume rm >/dev/null 2>&1 || true
    rm -rf -- "$IMMICH_BASE" "$IMMICH_STATE"
}

scenario_seed() {
    immich_prepare "$SCENARIO_SUCCESS_CASE"
    # backup_repository_create follows with the paths of this layout
    immich_layout >"$IMMICH_STATE/facility-applied"
    manifest_save seed "$IMMICH_BASE"
}

scenario_inject() {
    immich_prepare "$1"
}

# --- interface: assertions ----------------------------------------------------------------

immich_assert_service_online() {
    local container
    for container in $IMMICH_CONTAINERS; do
        assert_eq "$(docker inspect -f '{{.State.Running}}' "$container" 2>/dev/null)" true "$container running" || return 1
    done
    immich_version_ok || { log "assertion failed: Immich version endpoint does not report $IMMICH_REFERENCE_VERSION"; return 1; }
}

# immich_assert_invoked true|false: pg_dump connected to immich_postgres
# since the run's mark (log_connections).
immich_assert_invoked() {
    local count
    count=$(docker logs --since "$(cat "$IMMICH_STATE/log-mark")" immich_postgres 2>&1 | grep -c 'application_name=pg_dump' || true)
    if [ "$1" = true ]; then
        [ "$count" -ge 1 ] || { log "assertion failed: pg_dump was not invoked"; return 1; }
    else
        assert_eq "$count" 0 "pg_dump invocations"
    fi
}

immich_assert_no_temporaries() {
    local dir prefix
    prefix=$(jq -r '.dump.prefix' "$(fixture_file)")
    for dir in "$(immich_dump_dir)" /var/tmp; do
        [ -d "$dir" ] || continue
        assert_eq "$(find "$dir/" -maxdepth 1 -name ".$prefix*.part" | wc -l | tr -d ' ')" 0 "temporary outputs in $dir" || return 1
    done
}

# immich_assert_dump published|none
immich_assert_dump() {
    local run name path identity entry plain
    run=$(immich_run_id) || { log "assertion failed: no pre job in the run"; return 1; }
    name=$(immich_dump_name "$run")
    path=$(immich_dump_dir)/$name
    entry=$(immich_manifest_json | jq -c --arg run "$run" '[.entries[] | select(.runId == $run)]')
    if [ "$1" = none ]; then
        if [ -e "$path" ] || [ -L "$path" ] || [ -e "/var/tmp/$name" ]; then
            log "assertion failed: a dump was published: $name"
            return 1
        fi
        assert_eq "$(jq 'length' <<<"$entry")" 0 "manifest entries of the current run" || return 1
        return 0
    fi
    [[ -f $path && ! -L $path ]] || { log "assertion failed: dump $path missing"; return 1; }
    assert_eq "$(stat -c '%u:%g %a %h' "$path")" \
        "$(jq -r '.dump | "\(.uid):\(.gid) \(.mode | ltrimstr("0")) 1"' "$(fixture_file)")" "dump owner, mode, links" || return 1
    gzip -t "$path" || { log "assertion failed: $path is no valid gzip"; return 1; }
    plain=$IMMICH_STATE/dump.sql
    gzip -dc "$path" >"$plain" || return 1
    [ -s "$plain" ] || { log "assertion failed: empty dump"; return 1; }
    assert_prefix "$(head -c 200 "$plain")" "--" "dump header" || return 1
    grep -q 'PostgreSQL database dump complete' "$plain" || { log "assertion failed: dump incomplete"; return 1; }
    grep -q 'IF EXISTS' "$plain" || { log "assertion failed: dump without --clean --if-exists"; return 1; }
    rm -f "$plain"
    identity=$(stat -c '%d %i' "$path")
    assert_eq "$(jq -r --arg name "$name" '[.[] | select(.name == $name) | "\(.device) \(.inode)"] | join(",")' <<<"$entry")" \
        "$identity" "manifest identity of the dump"
}

# immich_assert_retention REMOVED_JSON PUBLISHED: exactly the removed history
# dumps are gone from disk and manifest (assertRetention of the reference test).
immich_assert_retention() {
    local removed=$1 published=$2 index=0 name path existed exists gone want="" got
    while IFS= read -r name; do
        path=$(immich_dump_dir)/$name
        existed=false
        if immich_in_snapshot "$(immich_start_manifest)" "$path"; then existed=true; fi
        exists=false
        if [ -e "$path" ] || [ -L "$path" ]; then exists=true; fi
        gone=false
        if jq -e --argjson i "$index" 'any(.[]; . == $i)' <<<"$removed" >/dev/null; then gone=true; fi
        if [ "$existed" = true ] && [ "$gone" = false ]; then
            assert_eq "$exists" true "history dump $index" || return 1
        else
            assert_eq "$exists" false "history dump $index" || return 1
        fi
        [ "$gone" = true ] || want="$want$name,"
        index=$((index + 1))
    done < <(immich_history_names)
    if [ "$published" = published ]; then
        want="$want$(immich_dump_name "$(immich_run_id)"),"
    fi
    got=$(immich_manifest_json | jq -r '[.entries[].name] | join(",")')
    assert_eq "$got," "$want" "manifest entries"
}

# immich_assert_core_readable: every core source and the dump are readable
# by the backup principal, symlinks are not followed; read ACLs are set.
immich_assert_core_readable() {
    local uid gid root acl
    uid=$(jq -r '.core.readableBy.uid' "$(fixture_file)")
    gid=$(jq -r '.core.readableBy.gid' "$(fixture_file)")
    while IFS= read -r root; do
        setpriv --reuid="$uid" --regid="$gid" --clear-groups find "$root" -type f -exec cat {} + >/dev/null ||
            { log "assertion failed: $root is not readable by $uid:$gid"; return 1; }
        acl=$(getfacl -cpn "$root")
        grep -qx "user:$uid:r-x" <<<"$acl" || { log "assertion failed: no read ACL on $root"; return 1; }
    done < <(scenario_backup_paths)
    setpriv --reuid="$uid" --regid="$gid" --clear-groups cat "$(immich_dump_dir)/$(immich_dump_name "$(immich_run_id)")" >/dev/null ||
        { log "assertion failed: the dump is not readable by $uid:$gid"; return 1; }
    immich_assert_snapshot_has_dump
}

# immich_assert_snapshot_has_dump: a restic snapshot of the core backup
# contains the current-run dump (its name carries the run id, so a match can
# only come from this run's core backup).
immich_assert_snapshot_has_dump() {
    local name path listing
    name=$(immich_dump_name "$(immich_run_id)")
    path=$(immich_dump_dir)/$name
    listing=$(restic_in_app --no-lock find "$name") || { log "assertion failed: restic find in the repository failed"; return 1; }
    grep -qF -- "$path" <<<"$listing" || { log "assertion failed: no snapshot contains $path"; return 1; }
}

scenario_assert_effects() {
    local expect target
    expect=$(fixture_case "$1" | jq -c '.expect')
    [ "$(cat "$IMMICH_STATE/run-case")" = "$1" ] || { log "assertion failed: the run was prepared for '$(cat "$IMMICH_STATE/run-case")'"; return 1; }
    immich_assert_service_online || return 1
    immich_assert_invoked "$(jq -r '.invoked' <<<"$expect")" || return 1
    immich_assert_no_temporaries || return 1
    immich_assert_dump "$(jq -r '.dump' <<<"$expect")" || return 1
    immich_assert_retention "$(jq -c '.removedHistory' <<<"$expect")" "$(jq -r '.dump' <<<"$expect")" || return 1
    target=$(fixture_inject "$1" | jq -r '.symlink.target // empty')
    if [ -n "$target" ]; then
        assert_eq "$(getfacl -cpn "$target" 2>&1)" "$(cat "$IMMICH_STATE/target-acl")" "ACL of the symlink target $target" || return 1
    fi
    if [ "$(fixture_inject "$1" | jq -r '.output // empty')" = partial ]; then
        assert_eq "$(cat "$IMMICH_STATE/partial-terminated" 2>/dev/null)" terminated "pg_dump terminated during the partial output" || return 1
    fi
    if [ "$(jq -r '.core' <<<"$expect")" = readable ]; then
        immich_assert_core_readable
    fi
}

# --- interface: reset -------------------------------------------------------------------

# immich_cleanup_run: the fixture's test-only cleanup. Deletes only the
# manifest-recorded dumps of the current run (run id, device/inode, regular
# file, link count 1, no symlink) and restores modes and ACLs from the
# trusted pre-test snapshot while the root identity is unchanged. Any
# mismatch deletes and mutates nothing.
immich_cleanup_run() {
    local start dir history run="" artifacts pass name device inode path mode acl
    start=$(immich_start_manifest)
    [ "$(sha256sum "$start" | cut -c1-64)" = "$(cat "$IMMICH_STATE/start-sha256")" ] ||
        { log "cleanup: the pre-test snapshot changed, mutating nothing"; return 1; }
    [ "$(stat -c '%d:%i' "$IMMICH_BASE" "$IMMICH_BASE/library")" = "$(cat "$IMMICH_STATE/root-identity")" ] ||
        { log "cleanup: the root identity changed, mutating nothing"; return 1; }
    dir=$(immich_dump_dir)
    history=$(immich_history_run_ids | jq -R . | jq -sc .)
    artifacts=$(immich_manifest_json | jq -c --argjson history "$history" '[.entries[] | select(.runId as $r | any($history[]; . == $r) | not)]')
    if [ "$(jq 'length' <<<"$artifacts")" -gt 0 ]; then
        run=$(immich_run_id) || { log "cleanup: dumps recorded without a run, deleting nothing"; return 1; }
        [[ -d $dir && ! -L $dir ]] || { log "cleanup: $dir is not a directory, deleting nothing"; return 1; }
    fi
    # Pass 1 checks every artifact, pass 2 deletes: a mismatch deletes nothing.
    for pass in check delete; do
        while IFS=$'\t' read -r name device inode; do
            [ -n "$name" ] || continue
            [ "$(jq -r --arg n "$name" '.[] | select(.name == $n) | .runId' <<<"$artifacts")" = "$run" ] ||
                { log "cleanup: $name belongs to another run, deleting nothing"; return 1; }
            [ -e "$dir/$name" ] || [ -L "$dir/$name" ] || continue
            [ "$(stat -c '%F %d %i %h' "$dir/$name")" = "regular file $device $inode 1" ] ||
                { log "cleanup: identity of $name does not match the manifest, deleting nothing"; return 1; }
            if [ "$pass" = delete ]; then
                rm -f -- "$dir/$name"
            fi
        done < <(jq -r '.[] | [.name, .device, .inode] | @tsv' <<<"$artifacts")
    done
    immich_manifest_store "$(immich_manifest_json | jq -c --argjson history "$history" \
        '.entries |= [.[] | select(.runId as $r | any($history[]; . == $r))]')"
    # path, mode and ACL of every non-symlink of the snapshot (awk: empty
    # fields would collapse in read with a tab IFS)
    while IFS=$'\t' read -r path mode acl; do
        [[ -e $path && ! -L $path ]] || continue
        if [ -z "$acl" ]; then
            setfacl -b "$path"
            chmod "$mode" "$path"
        else
            setfacl --set="${acl%,}" "$path"
        fi
    done < <(awk -F'\t' 'NF >= 8 && $2 != "l" { print $1 "\t" $3 "\t" $8 }' "$start")
}

# immich_prove_end_state: the tree equals the pre-test snapshot minus exactly
# the manifest-owned dumps retention removed (removed from disk and from the
# agent manifest). Directory sizes are not compared (file system specific).
immich_prove_end_state() {
    local sequence start name path manifest removed
    sequence=$(cat "$IMMICH_STATE/run-seq")
    start=$(immich_start_manifest)
    manifest=$(immich_manifest_json)
    removed=$IMMICH_STATE/removed-paths
    # first line: a sentinel, so the list is never an empty first awk input
    echo '#' >"$removed"
    while IFS= read -r name; do
        path=$(immich_dump_dir)/$name
        immich_in_snapshot "$start" "$path" || continue
        [[ ! -e $path && ! -L $path ]] || continue
        if jq -e --arg n "$name" 'any(.entries[]; .name == $n)' <<<"$manifest" >/dev/null; then
            log "end state: $name is gone but still recorded, it was not removed by retention"
            return 1
        fi
        echo "$path" >>"$removed"
    done < <(immich_history_names)
    manifest_save "immich-$sequence.end" "$IMMICH_BASE"
    awk -F'\t' -v OFS='\t' 'NR == FNR { gone[$0] = 1; next } !($1 in gone) { if ($2 == "d") $5 = "-"; print }' \
        "$removed" "$start" >"$OUT_DIR/manifests/immich-$sequence.expected.tsv"
    awk -F'\t' -v OFS='\t' '{ if ($2 == "d") $5 = "-"; print }' \
        "$OUT_DIR/manifests/immich-$sequence.end.tsv" >"$OUT_DIR/manifests/immich-$sequence.end-normalized.tsv"
    manifest_equal "immich-$sequence.expected" "immich-$sequence.end-normalized"
}

scenario_reset() {
    [ -f "$IMMICH_STATE/run-seq" ] || die "scenario_reset without a prepared run"
    immich_undo_service_injections || return 1
    immich_cleanup_run || return 1
    immich_prove_end_state || return 1
    # ready for the next backup (the resilience steps run the success case
    # without an inject)
    immich_prepare "$SCENARIO_SUCCESS_CASE"
}

# --- interface: stall -------------------------------------------------------------------

# immich_postmaster_pid: host PID of the PostgreSQL postmaster in immich_postgres.
immich_postmaster_pid() {
    local inner pid
    # shellcheck disable=SC2016 # PGDATA of the container
    inner=$(docker exec immich_postgres sh -c 'head -n 1 "${PGDATA:-/var/lib/postgresql/data}/postmaster.pid"')
    for pid in $(docker top immich_postgres -eo pid | awk 'NR > 1 { print $1 }'); do
        if [ "$(awk '/^NSpid:/ { print $NF }' "/proc/$pid/status" 2>/dev/null)" = "$inner" ]; then
            echo "$pid"
            return 0
        fi
    done
    return 1
}

# scenario_stall on|off: the postmaster is stopped (SIGSTOP), so the pre
# phase's pg_dump hangs in its connection until off; running sessions and
# all containers stay up.
scenario_stall() {
    local pid
    case ${1:-} in
        on)
            pid=$(immich_postmaster_pid) || die "postmaster of immich_postgres not found"
            echo "$pid" >"$IMMICH_STATE/stall-pid"
            kill -STOP "$pid"
            ;;
        off)
            [ -f "$IMMICH_STATE/stall-pid" ] || return 0
            kill -CONT "$(cat "$IMMICH_STATE/stall-pid")" 2>/dev/null || true
            rm -f "$IMMICH_STATE/stall-pid"
            ;;
        *) die "scenario_stall on|off" ;;
    esac
}

scenario_collect_logs() {
    local dir=$1
    immich_compose ps -a >"$dir/immich-ps.txt" 2>&1 || true
    immich_compose logs --no-color --timestamps >"$dir/immich.log" 2>&1 || true
    cp "$IMMICH_STATE/policy.yaml" "$dir/immich-policy.yaml" 2>/dev/null || true
    cp "$IMMICH_STATE/partial-watch.log" "$dir/immich-partial-watch.log" 2>/dev/null || true
    immich_manifest_json >"$dir/immich-agent-manifest.json" 2>/dev/null || true
}
