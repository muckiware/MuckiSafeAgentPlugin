# shellcheck shell=bash
# Jellyfin scenario of the Linux service E2E (Task 32). Implements the
# scenario interface of tests/E2E/README.md; sourced by run-linux-e2e.sh
# after lib.sh and the step scripts, by the selftest after lib.sh only.
#
# Jellyfin is a native systemd service: the official packages of
# JELLYFIN_REFERENCE_VERSION (pinned with SHA256 in
# fixtures/jellyfin/package.env) install jellyfin.service, data
# /var/lib/jellyfin and config /etc/jellyfin. Two hooks: pre stops the unit
# and proves the trees readable for UID 33, finally starts it again and waits
# for HTTP 200 (examples/actions/jellyfin*.json).
#
# Fixture injections are produced by systemd itself through runtime drop-ins
# (/run/systemd/system/jellyfin.service.d/muwa-e2e-*.conf), never by a fake
# systemctl (see agent/internal/primitives/reference_jellyfin_test.go):
#
#   stopExit         RefuseManualStop=yes: `systemctl stop` fails (exit 4,
#                    "operation refused"), the unit stays active with its cgroup
#   cgroupPopulated  KillMode=process plus an ExecStartPost child: after the
#                    stop the unit is inactive but its cgroup still populated
#   startFails       RefuseManualStart=yes: `systemctl start` fails, the unit
#                    stays inactive and the endpoint unavailable
#   initiallyInactive the unit is stopped before the backup
#   runs             the whole backup (pre, core, finally) runs that often
#   remove, symlink  applied by an ExecStopPost hook once Jellyfin has exited
#                    (its SQLite database is closed, nothing can recreate the
#                    file) and undone by an ExecStartPre hook before the next
#                    start, so the agent's checks after the stop see exactly
#                    the injected tree and finally finds Jellyfin startable
#   coreFails        core_break (restic repository unwritable for www-data)
#
# Bash 3.2 at top level and in scenario_cases/scenario_skip_reason (the
# selftest and the dry run source this file on macOS); everything else runs
# on the Linux runner only.

# Read by run-linux-e2e.sh, lib.sh and selftest.sh.
# shellcheck disable=SC2034
SCENARIO_ARCHES="amd64 arm64"
# shellcheck disable=SC2034
SCENARIO_SUCCESS_CASE=success
# The fixture states every phase explicitly; top-level outcomes would be pre.
# shellcheck disable=SC2034
SCENARIO_DEFAULT_PHASE=pre

JF_UNIT=jellyfin.service
JF_DATA_ROOT=/var/lib/jellyfin
JF_CONFIG_ROOT=/etc/jellyfin
JF_HEALTH_URL=http://127.0.0.1:8096/System/Info/Public
JF_DROPIN_DIR=/run/systemd/system/jellyfin.service.d
JF_WORK=$WORK_DIR/jellyfin
JF_FIXTURE_DIR=$E2E_DIR/fixtures/jellyfin
JF_BACKUP_UID=33
# Drop-ins a case may leave; the stall drop-in belongs to scenario_stall.
JF_CASE_DROPINS="refuse-stop refuse-start leftover aside"

# --- interface: static parts ------------------------------------------------------

# Native packages, no OCI image.
scenario_images() {
    return 0
}

scenario_source_mounts() {
    echo "$JF_DATA_ROOT"
    echo "$JF_CONFIG_ROOT"
}

scenario_backup_paths() {
    echo "$JF_DATA_ROOT"
    echo "$JF_CONFIG_ROOT"
}

# Equivalent to fixture_cases, but independent of SCENARIO_NAME: the
# selftest probe sources the scenario without setting it.
scenario_cases() {
    jq -r '.scenarios[].name' "$JF_FIXTURE_DIR/fixture.json"
}

# Stop and start are safe; both hooks fail the backup on error (finally
# cannot replace a recorded primary error, see BackupRunCoordinator).
# pre: stop (30 s) plus two bounded ACL walks; finally: start plus
# wait-http, Jellyfin answers within seconds.
scenario_hooks() {
    echo "pre examples/actions/jellyfin.json safe 90 true"
    echo "finally examples/actions/jellyfin-finally.json safe 120 true"
}

# scenario_policy: the Jellyfin aliases of the contract, copied verbatim
# from examples/policies/linux-host.yaml (entries of other services left out).
scenario_policy() {
    local keep policy=$JF_WORK/policy.yaml
    keep=" $(jq -r '[.aliases[] | keys[]] | join(" ")' "$E2E_DIR/contracts/jellyfin.json") "
    install -d -m 0755 "$JF_WORK"
    awk -v keep="$keep" '
        /^version:/ { print; next }
        /^[a-z]+:/ { section = $0; printed = 0; take = 0; next }
        /^  [a-z0-9-]+:/ {
            name = $1; sub(/:$/, "", name); take = index(keep, " " name " ") > 0
            if (take && !printed) { print ""; print section; printed = 1 }
            if (take) print
            next
        }
        /^    / { if (take) print }
    ' "$REPO_ROOT/examples/policies/linux-host.yaml" >"$policy"
    local alias
    for alias in $keep; do
        grep -q "^  $alias:\$" "$policy" || die "policy: alias $alias missing in examples/policies/linux-host.yaml"
    done
    echo "$policy"
}

# --- helpers --------------------------------------------------------------------

jf_package_env() {
    # shellcheck source=../fixtures/jellyfin/package.env
    . "$JF_FIXTURE_DIR/package.env"
}

# jf_host_path REL: a fixture-relative path ("data/...", "config/...") on
# the host, through the fixture's policyRoots.
jf_host_path() {
    local top=${1%%/*} root
    root=$(jq -r --arg top "$top" '.policyRoots | to_entries[] | select(.value == $top) | .key' "$(fixture_file)")
    [ -n "$root" ] || die "fixture path $1 is outside the policy roots"
    if [ "$top" = "$1" ]; then
        echo "$root"
    else
        echo "$root/${1#*/}"
    fi
}

jf_unit_state() {
    systemctl show -p ActiveState --value "$JF_UNIT"
}

jf_http_status() {
    curl -s -o "$JF_WORK/health.json" -w '%{http_code}' --max-time 5 "$JF_HEALTH_URL" || true
}

jf_healthy() {
    [ "$(jf_unit_state)" = active ] && [ "$(jf_http_status)" = 200 ]
}

jf_wait_healthy() {
    wait_for 180 "jellyfin.service active and public info HTTP 200" jf_healthy
}

jf_ensure_active() {
    [ "$(jf_unit_state)" = active ] || systemctl start "$JF_UNIT"
    jf_wait_healthy
}

# jf_assert_health STATUS: 200 must be the pinned Jellyfin; any other
# expectation (the fake's 503) means "not available": the real endpoint is
# unreachable (curl 000) or answers 5xx while the unit is down.
jf_assert_health() {
    local expected=$1 actual
    if [ "$expected" = 200 ]; then
        wait_for 30 "public info HTTP 200" jf_healthy
        assert_eq "$(jq -r '.Version' "$JF_WORK/health.json")" "$JELLYFIN_REFERENCE_VERSION" "Jellyfin version"
        return 0
    fi
    actual=$(jf_http_status)
    case $actual in
        000|5??) ;;
        *) log "assertion failed: public info answers $actual, expected unavailable ($expected)"; return 1 ;;
    esac
}

jf_dropin() {
    install -d -m 0755 "$JF_DROPIN_DIR"
    printf '%s\n' "$2" >"$JF_DROPIN_DIR/muwa-e2e-$1.conf"
    systemctl daemon-reload
}

jf_dropins_remove() {
    local name
    for name in "$@"; do
        rm -f "$JF_DROPIN_DIR/muwa-e2e-$name.conf"
    done
    rmdir "$JF_DROPIN_DIR" 2>/dev/null || true
    systemctl daemon-reload
}

jf_invocation() {
    systemctl show -p InvocationID --value "$JF_UNIT"
}

# Processes in the unit's cgroup other than Jellyfin itself.
jf_leftover_count() {
    local group pids
    group=$(systemctl show -p ControlGroup --value "$JF_UNIT")
    [[ -n $group && -f /sys/fs/cgroup$group/cgroup.procs ]] || { echo 0; return 0; }
    pids=$(paste -sd, "/sys/fs/cgroup$group/cgroup.procs")
    [ -n "$pids" ] || { echo 0; return 0; }
    ps -o comm= -p "$pids" | grep -cx sleep || true
}

# --- service --------------------------------------------------------------------

jf_check_fixture() {
    local fixture
    fixture=$(fixture_file)
    assert_eq "$(jq -r '.unit.name' "$fixture")" "$JF_UNIT" "fixture unit"
    assert_eq "$(jq -r '.policyOrigin + .health.path' "$fixture")" "$JF_HEALTH_URL" "fixture health URL"
    assert_eq "$(jf_host_path data)" "$JF_DATA_ROOT" "fixture data root"
    assert_eq "$(jf_host_path config)" "$JF_CONFIG_ROOT" "fixture config root"
}

# jf_download NAME PATH SHA256: one pinned package from the official pool.
jf_download() {
    local file=$JF_WORK/packages/$1.deb
    curl -fsSL --retry 3 -o "$file" "$JELLYFIN_PACKAGE_BASE_URL/$2"
    echo "$3  $file" | sha256sum --check --strict --quiet || die "checksum mismatch for $2"
    echo "$file"
}

jf_install_packages() {
    local arch suffix server ffmpeg meta web server_deb ffmpeg_deb
    jf_package_env
    arch=$(dpkg --print-architecture)
    assert_eq "$arch" "$E2E_ARCH" "dpkg architecture"
    suffix=$(tr '[:lower:]' '[:upper:]' <<<"$arch")
    server=JELLYFIN_SERVER_DEB_$suffix
    ffmpeg=JELLYFIN_FFMPEG_DEB_$suffix
    local server_sha=JELLYFIN_SERVER_SHA256_$suffix ffmpeg_sha=JELLYFIN_FFMPEG_SHA256_$suffix
    install -d -m 0755 "$JF_WORK/packages"
    meta=$(jf_download jellyfin "$JELLYFIN_META_DEB" "$JELLYFIN_META_SHA256")
    web=$(jf_download jellyfin-web "$JELLYFIN_WEB_DEB" "$JELLYFIN_WEB_SHA256")
    server_deb=$(jf_download jellyfin-server "${!server}" "${!server_sha}")
    ffmpeg_deb=$(jf_download jellyfin-ffmpeg8 "${!ffmpeg}" "${!ffmpeg_sha}")
    apt-get update -qq
    # Dependencies come from the runner's Ubuntu archive; the four Jellyfin
    # packages only from the verified files.
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq --no-install-recommends \
        "$meta" "$web" "$server_deb" "$ffmpeg_deb"
    assert_eq "$(dpkg-query -W -f '${Version}' jellyfin-server)" "$JELLYFIN_PACKAGE_VERSION" "jellyfin-server version"
    assert_eq "$(dpkg-query -W -f '${Version}' jellyfin-web)" "$JELLYFIN_PACKAGE_VERSION" "jellyfin-web version"
    assert_eq "$(dpkg-query -W -f '${Version}' jellyfin-ffmpeg8)" "$JELLYFIN_FFMPEG_PACKAGE_VERSION" "jellyfin-ffmpeg8 version"
}

jf_root_identity() {
    stat -c '%n %d:%i' "$JF_DATA_ROOT" "$JF_CONFIG_ROOT"
}

# scenario_prepare_host (stack_prepare, before docker compose up): the pinned
# packages install the unit and create both roots owned by jellyfin, so the
# read-only bind mounts of app and scheduler point at the service's own
# directories (Docker never creates them). The roots are never recreated
# afterwards: the mounts reference these inodes.
scenario_prepare_host() {
    jf_check_fixture
    jf_package_env
    grep -qx "VERSION_ID=\"$JELLYFIN_PACKAGE_UBUNTU\"" /etc/os-release ||
        die "the pinned Jellyfin packages are built for Ubuntu $JELLYFIN_PACKAGE_UBUNTU"
    if dpkg-query -W jellyfin-server >/dev/null 2>&1 && [ ! -f "$JF_WORK/installed" ]; then
        die "refusing to take over a Jellyfin installation this run did not create"
    fi
    if [ -e "$JF_DATA_ROOT" ] || [ -e "$JF_CONFIG_ROOT" ]; then
        die "$JF_DATA_ROOT or $JF_CONFIG_ROOT exists before the package installation"
    fi
    install -d -m 0755 "$JF_WORK"
    touch "$JF_WORK/installed"
    jf_install_packages
    install -o root -g root -m 0755 "$JF_FIXTURE_DIR/unit-hook.sh" "$JF_WORK/unit-hook.sh"
    assert_eq "$(stat -c %U "$JF_DATA_ROOT" "$JF_CONFIG_ROOT" | sort -u)" jellyfin "owner of the policy roots"
    # The aside moves must be renames (same file system as the roots).
    assert_eq "$(stat -c %d "$JF_WORK")" "$(stat -c %d "$JF_DATA_ROOT")" "file system of WORK_DIR and $JF_DATA_ROOT"
    jf_root_identity >"$JF_WORK/root-identity-installed"
}

# scenario_up: pinned Jellyfin in its documented start state: unit active,
# public info HTTP 200 with the reference version, database present, roots
# still the objects the stack mounts.
scenario_up() {
    [ -f "$JF_WORK/installed" ] || die "scenario_prepare_host did not install Jellyfin"
    jf_root_identity | diff -u "$JF_WORK/root-identity-installed" - >&2
    systemctl enable --now "$JF_UNIT"
    jf_wait_healthy
    assert_eq "$(jq -r '.Version' "$JF_WORK/health.json")" "$JELLYFIN_REFERENCE_VERSION" "Jellyfin version"
    wait_for 60 "Jellyfin database" test -f "$JF_DATA_ROOT/data/jellyfin.db"
    # As root: this checks the mount only. The scheduler runs as www-data and
    # may not traverse the jellyfin-owned root before the agent's read ACL.
    local service
    for service in app scheduler; do
        stack exec -T -u root "$service" test -d "$JF_DATA_ROOT/data" ||
            die "$service does not see $JF_DATA_ROOT through its bind mount"
    done
}

scenario_down() {
    scenario_stall off 2>/dev/null || true
    rm -f "$JF_DROPIN_DIR"/muwa-e2e-*.conf
    rmdir "$JF_DROPIN_DIR" 2>/dev/null || true
    systemctl daemon-reload 2>/dev/null || true
    [ -f "$JF_WORK/installed" ] || return 0
    systemctl disable --now "$JF_UNIT" 2>/dev/null || true
    DEBIAN_FRONTEND=noninteractive apt-get purge -y -qq jellyfin jellyfin-server jellyfin-web jellyfin-ffmpeg8 >/dev/null 2>&1 || true
    rm -rf "$JF_DATA_ROOT" "$JF_CONFIG_ROOT" "$JF_WORK"
}

scenario_collect_logs() {
    journalctl -u "$JF_UNIT" --no-pager >"$1/jellyfin.journal" 2>&1 || true
    systemctl cat "$JF_UNIT" >"$1/jellyfin.unit.txt" 2>&1 || true
    dpkg-query -W 'jellyfin*' >"$1/jellyfin-packages.txt" 2>&1 || true
    [ ! -d "$JF_WORK/displaced" ] || ls -la "$JF_WORK/displaced" >"$1/jellyfin-displaced.txt" 2>&1 || true
}

# --- seed and manifests -----------------------------------------------------------

# scenario_seed: the fixture seed inside the live roots. Entries the service
# already created keep their content (the real database, system.xml, ...) and
# get the fixture mode; missing ones are created with the fixture content,
# owned like their root. Then the baseline: manifest, ACLs, root identity.
scenario_seed() {
    local fixture path mode content host owner
    fixture=$(fixture_file)
    owner=$(stat -c %U:%G "$JF_DATA_ROOT")
    : >"$JF_WORK/seed-paths"
    : >"$JF_WORK/service-owned"
    while IFS=$'\t' read -r path mode; do
        host=$(jf_host_path "$path")
        [ -d "$host" ] || install -d -o "${owner%%:*}" -g "${owner#*:}" -m "$mode" "$host"
        chmod "$mode" "$host"
        echo "$host" >>"$JF_WORK/seed-paths"
    done < <(jq -r '.seed.directories[] | [.path, .mode] | @tsv' "$fixture")
    while IFS=$'\t' read -r path mode content; do
        host=$(jf_host_path "$path")
        if [ -e "$host" ]; then
            echo "$host" >>"$JF_WORK/service-owned"
        else
            printf '%s' "$content" >"$host"
            chown "$owner" "$host"
        fi
        chmod "$mode" "$host"
        echo "$host" >>"$JF_WORK/seed-paths"
    done < <(jq -r '.seed.files[] | [.path, .mode, .content] | @tsv' "$fixture")
    jf_root_identity >"$JF_WORK/root-identity"
    jf_acls >"$JF_WORK/acl-before.txt"
    jf_manifest_save seed
}

# jf_acls: every extended ACL beneath the roots (symlinks never followed).
# A file the running service removes during the walk is no failure; the
# listing is compared as a whole anyway.
jf_acls() {
    getfacl -R -P -p --skip-base "$JF_DATA_ROOT" "$JF_CONFIG_ROOT" 2>/dev/null || true
}

# jf_manifest_save NAME: manifest_save over both roots, reduced to the seed
# entries. Content (size, hash) of files the running service owns and writes
# (its SQLite database, system.xml, ...) and the size of directories (grows
# with the service's own files) are masked; type, mode, owner, link target and
# ACL of every seed entry stay compared.
jf_manifest_save() {
    local file
    manifest_save "$1" "$JF_DATA_ROOT" "$JF_CONFIG_ROOT"
    file=$OUT_DIR/manifests/$1.tsv
    awk -F '\t' -v OFS='\t' -v seed="$JF_WORK/seed-paths" -v owned="$JF_WORK/service-owned" '
        BEGIN {
            while ((getline line < seed) > 0) keep[line] = 1
            while ((getline line < owned) > 0) mask[line] = 1
        }
        ($1 in keep) {
            if ($1 in mask) { $5 = "*"; $6 = "*" }
            if ($2 == "d") $5 = "*"
            print
        }
    ' "$file" >"$file.tmp"
    mv -f "$file.tmp" "$file"
}

jf_manifest_name() {
    printf '%s' "$1" | tr -c 'A-Za-z0-9_.-' '_'
}

# jf_restore_acls: fixture cleanup "aclRestore": only when both roots are the
# very objects of the trusted pre-test snapshot (else mutate nothing), every
# extended ACL is removed and the snapshot restored; the result is compared
# with the snapshot.
jf_restore_acls() {
    if ! jf_root_identity | diff -u "$JF_WORK/root-identity" - >&2; then
        log "root identity changed since the snapshot: ACLs left untouched"
        return 1
    fi
    setfacl -R -P -b "$JF_DATA_ROOT" "$JF_CONFIG_ROOT"
    if [ -s "$JF_WORK/acl-before.txt" ]; then
        setfacl -P --restore="$JF_WORK/acl-before.txt"
    fi
    jf_acls | diff -u "$JF_WORK/acl-before.txt" - >&2
}

# --- cases ------------------------------------------------------------------------

jf_inject_value() {
    fixture_inject "$1" "$2"
}

# jf_plan_paths CASE: the remove/symlink lines of unit-hook.sh's plan.
jf_plan_paths() {
    local remove symlink host
    remove=$(jf_inject_value "$1" remove | jq -r '. // empty')
    if [ -n "$remove" ]; then
        host=$(jf_host_path "$remove")
        printf 'remove\t%s\t%s\t\n' "$host" "$JF_WORK/aside$host"
    fi
    symlink=$(jf_inject_value "$1" symlink)
    if [ -n "$symlink" ]; then
        host=$(jf_host_path "$(jq -r '.path' <<<"$symlink")")
        printf 'symlink\t%s\t%s\t%s\n' "$host" "$JF_WORK/aside$host" "$(jq -r '.target' <<<"$symlink")"
    fi
}

# jf_aside_pending: an injected object still waits for its restore.
jf_aside_pending() {
    local kind host aside
    while IFS=$'\t' read -r kind host aside _; do
        [ -n "$kind" ] || continue
        if [ -e "$aside" ] || [ -L "$aside" ]; then
            return 0
        fi
    done <"$JF_WORK/inject.plan"
    return 1
}

jf_inject_paths() {
    [ ! -e "$JF_WORK/aside" ] || { log "stale injection state in $JF_WORK/aside"; return 1; }
    jf_plan_paths "$1" >"$JF_WORK/inject.plan"
    [ -s "$JF_WORK/inject.plan" ] || { rm -f "$JF_WORK/inject.plan"; return 0; }
    jf_dropin aside "[Service]
ExecStopPost=+$JF_WORK/unit-hook.sh inject $JF_WORK/inject.plan
ExecStartPre=+$JF_WORK/unit-hook.sh restore $JF_WORK/inject.plan"
}

# The unit stops with KillMode=process: a child started by ExecStartPost
# (sleep, as root) survives the stop in the unit's cgroup.
jf_inject_cgroup_populated() {
    jf_dropin leftover "[Service]
KillMode=process
ExecStartPost=+/bin/sh -c 'sleep infinity &'"
    systemctl restart "$JF_UNIT"
    jf_wait_healthy
    [ "$(jf_leftover_count)" -ge 1 ] || { log "no leftover process in the jellyfin cgroup"; return 1; }
}

jf_stop_unit() {
    systemctl stop "$JF_UNIT"
    assert_eq "$(jf_unit_state)" inactive "jellyfin.service before the backup"
}

# jf_extra_runs CASE: "runs": N repeats pre, core and finally N times; the
# orchestrator performs the last run, the earlier ones run (and are asserted)
# here, each from the injected start state.
jf_extra_runs() {
    local runs run inactive
    runs=$(jf_inject_value "$1" runs)
    inactive=$(jf_inject_value "$1" initiallyInactive)
    run=1
    while [ "$run" -lt "${runs:-1}" ]; do
        [ "$inactive" != true ] || jf_stop_unit
        jf_mark_before_backup
        backup_trigger
        assert_case "$1"
        run_save "$1 (run $run of $runs)" >/dev/null
        scenario_assert_effects "$1"
        run=$((run + 1))
    done
}

# scenario_inject CASE: from the documented start state (active, HTTP 200)
# to the case's injected state.
scenario_inject() {
    local name=$1
    jf_ensure_active
    jf_inject_paths "$name"
    if [ "$(jf_inject_value "$name" stopExit)" ] && [ "$(jf_inject_value "$name" stopExit)" != 0 ]; then
        jf_dropin refuse-stop "[Unit]
RefuseManualStop=yes"
    fi
    if [ "$(jf_inject_value "$name" startFails)" = true ]; then
        jf_dropin refuse-start "[Unit]
RefuseManualStart=yes"
    fi
    if [ "$(jf_inject_value "$name" cgroupPopulated)" = true ]; then
        jf_inject_cgroup_populated
    fi
    if [ "$(jf_inject_value "$name" coreFails)" = true ]; then
        core_break
    fi
    jf_extra_runs "$name"
    if [ "$(jf_inject_value "$name" initiallyInactive)" = true ]; then
        jf_stop_unit
    fi
    jf_mark_before_backup
}

# jf_mark_before_backup: unit invocation and snapshot count right before a
# case's backup; scenario_assert_effects compares with them, scenario_reset
# drops them (the resilience cases assert without an injection).
jf_mark_before_backup() {
    jf_invocation >"$JF_WORK/invocation-before"
    jf_snapshot_count >"$JF_WORK/snapshots-before"
}

# Read-only listing (--no-lock): also works while core_break holds the
# repository unwritable.
jf_snapshot_count() {
    restic_in_app snapshots --no-lock --json | jq 'length'
}

# scenario_assert_effects CASE: unit state, HTTP health, readability for the
# backup principal and whether the unit really went through a stop.
scenario_assert_effects() {
    local name=$1 expect unit health
    expect=$(fixture_case "$name" | jq -c '.expect')
    unit=$(jq -r '.unit // empty' <<<"$expect")
    health=$(jq -r '.healthStatus // empty' <<<"$expect")
    [ -z "$unit" ] || assert_eq "$(jf_unit_state)" "$unit" "jellyfin.service after finally"
    [ -z "$health" ] || jf_assert_health "$health"
    case $(jq -r '.core // ""' <<<"$expect") in
        readable) jf_assert_readable ;;
    esac
    [ ! -f "$JF_WORK/invocation-before" ] || jf_assert_transition "$name" "$expect"
    [ ! -f "$JF_WORK/snapshots-before" ] || jf_assert_snapshot "$expect"
}

# A readable core wrote exactly one new snapshot that holds every seed entry
# (data, database and config of the stopped service); a failed or skipped
# core wrote none.
jf_assert_snapshot() {
    local before after listing path
    before=$(cat "$JF_WORK/snapshots-before")
    after=$(jf_snapshot_count)
    if [ "$(jq -r '.core // ""' <<<"$1")" != readable ]; then
        assert_eq "$after" "$before" "restic snapshots without a successful core"
        return 0
    fi
    assert_eq "$after" "$((before + 1))" "restic snapshots after the core"
    listing=$(restic_in_app ls --no-lock latest)
    while IFS= read -r path; do
        grep -qxF -- "$path" <<<"$listing" || { log "assertion failed: $path missing in the snapshot"; return 1; }
    done <"$JF_WORK/seed-paths"
}

# The core read the stopped trees as www-data: with the retained read ACLs
# (production keeps them until the E2E cleanup) every seed entry and every
# required path of the contract checks is readable for UID 33.
jf_assert_readable() {
    local path
    while IFS= read -r path; do
        if [ -d "$path" ]; then
            setpriv --reuid="$JF_BACKUP_UID" --regid="$JF_BACKUP_UID" --clear-groups -- ls -A "$path" >/dev/null
        else
            setpriv --reuid="$JF_BACKUP_UID" --regid="$JF_BACKUP_UID" --clear-groups -- cat "$path" >/dev/null
        fi || { log "assertion failed: $path is not readable for UID $JF_BACKUP_UID"; return 1; }
    done <"$JF_WORK/seed-paths"
    assert_contains "$(getfacl -p --skip-base "$JF_DATA_ROOT" "$JF_CONFIG_ROOT")" "user:$JF_BACKUP_UID:r-x" "read ACL of the roots"
}

# A refused stop keeps the very same service invocation; every other case
# that started Jellyfin from an active unit went through stop and start.
jf_assert_transition() {
    local name=$1 expect=$2 before after
    before=$(cat "$JF_WORK/invocation-before")
    after=$(jf_invocation)
    if [ -n "$(jf_inject_value "$name" stopExit)" ] && [ "$(jf_inject_value "$name" stopExit)" != 0 ]; then
        assert_eq "$after" "$before" "jellyfin.service invocation after a refused stop"
        assert_contains "$(job_of pre | jq -r '.resultSummary')" "exited with" "pre failed at systemctl stop"
    elif [ -n "$before" ] && [ "$(jq -r '.finally.outcome' <<<"$expect")" = succeeded ]; then
        [ "$after" != "$before" ] || { log "assertion failed: jellyfin.service was not restarted by the backup"; return 1; }
    fi
}

# scenario_reset CASE: undo the injection, fixture cleanup (unit active,
# read ACLs restored from the trusted snapshot) and proof of the end state.
scenario_reset() {
    local name=$1 manifest
    # shellcheck disable=SC2086
    jf_dropins_remove $JF_CASE_DROPINS
    if [ -f "$JF_WORK/inject.plan" ]; then
        # Normally finally's ExecStartPre has restored the tree already; a
        # pending restore happens with the unit stopped.
        if jf_aside_pending; then
            [ "$(jf_unit_state)" != active ] || systemctl stop "$JF_UNIT"
            "$JF_WORK/unit-hook.sh" restore "$JF_WORK/inject.plan"
        fi
        rm -f "$JF_WORK/inject.plan"
    fi
    if [ "$(jf_inject_value "$name" coreFails)" = true ]; then
        core_repair
    fi
    if [ "$(jf_leftover_count)" -gt 0 ]; then
        # Default KillMode again: the restart removes the leftover processes.
        systemctl restart "$JF_UNIT"
    fi
    jf_ensure_active
    assert_eq "$(jf_leftover_count)" 0 "leftover processes in the jellyfin cgroup"
    [ ! -d "$JF_WORK/displaced" ] || { log "objects were recreated at an injected path: $JF_WORK/displaced"; return 1; }
    jf_restore_acls
    rm -f "$JF_WORK/invocation-before" "$JF_WORK/snapshots-before"
    manifest=reset-$(jf_manifest_name "$name")
    jf_manifest_save "$manifest"
    manifest_equal seed "$manifest"
}

# scenario_stall on|off: the first host effect of pre is the stop; with the
# stall drop-in its ExecStop (as root, before Jellyfin gets any signal) blocks
# until "off" removes the flag. TimeoutStopSec covers the longest resilience
# case (lease expiry: 30 s lease + 15 s).
scenario_stall() {
    case $1 in
        on)
            install -d -m 0755 "$JF_WORK"
            touch "$JF_WORK/stall"
            jf_dropin stall "[Service]
ExecStop=+$JF_WORK/unit-hook.sh stall $JF_WORK/stall
TimeoutStopSec=600"
            ;;
        off)
            rm -f "$JF_WORK/stall"
            jf_dropins_remove stall
            ;;
        *) die "scenario_stall on|off" ;;
    esac
}
