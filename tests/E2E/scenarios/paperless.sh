# shellcheck shell=bash
# Paperless-ngx scenario of the Linux service E2E (Task 32). Implements the
# scenario interface of tests/E2E/README.md against the pinned Paperless
# stack (tests/E2E/fixtures/paperless/compose.yaml) and replays every case of
# fixtures/paperless/fixture.json the way the agent reference test
# (agent/internal/primitives/reference_paperless_test.go) does with fakes.
#
# Hooks: pre = examples/actions/paperless.json (four policy-bound
# filesystem.assert-readable checks, read only, before the first artifact:
# safe), post = examples/actions/paperless-post.json (service.wait-http on
# paperless-web after the core created artifacts: non_retryable). Post runs
# only after a successful core (Facility dispatches its after-event only
# then). The broken legacy paperless_pre.sh is never executed (frozen
# decision in docs/reference-services.md); every case asserts that neither
# the installed policy, the action definitions nor the agent journal
# reference it.
#
# Web state per case (fixture "web"), always on the policy endpoint
# http://127.0.0.1:8091/:
#   302 /accounts/login/?next=/  the real Paperless login redirect (start state)
#   200                          the real Paperless with
#                                PAPERLESS_AUTO_LOGIN_USERNAME (no redirect)
#   302 <other location>         Paperless web stopped, a responder from the
#                                pinned Paperless image answers / with that
#                                Location (a foreign service on the port)
#
# Sourced by run-linux-e2e.sh and the selftest (also by macOS bash 3.2):
# the top level only assigns variables and defines functions.

# The selftest sources the scenario without the orchestrator's SCENARIO_NAME.
: "${SCENARIO_NAME:=paperless}"
# shellcheck disable=SC2034 # read by the orchestrator and the selftest
SCENARIO_ARCHES="amd64 arm64"
# shellcheck disable=SC2034
SCENARIO_SUCCESS_CASE="success with the login redirect"
# shellcheck disable=SC2034
SCENARIO_DEFAULT_PHASE=pre

PAPERLESS_PROJECT=muwa-e2e-paperless
PAPERLESS_BASE=/data/dokumente
PAPERLESS_STATE_DIR=$WORK_DIR/paperless
PAPERLESS_STALL_CONTAINER=muwa-e2e-paperless-stall
PAPERLESS_FOREIGN_CONTAINER=muwa-e2e-paperless-foreign
PAPERLESS_LOGIN_LOCATION="/accounts/login/?next=/"
PAPERLESS_ADMIN_USER=e2e-admin

# --- interface: static parts ------------------------------------------------------

scenario_images() {
    cat <<'EOF'
PAPERLESS_WEBSERVER_IMAGE
PAPERLESS_MARIADB_IMAGE
PAPERLESS_VALKEY_IMAGE
PAPERLESS_GOTENBERG_IMAGE
PAPERLESS_TIKA_IMAGE
EOF
}

# One read-only mount of the common parent of the four policy roots (it
# holds nothing else): the "consume root missing" case removes and recreates
# a root, which a bind mount of the root itself would no longer see.
scenario_source_mounts() {
    echo "$PAPERLESS_BASE"
}

# The four policy roots in the order of the fixture's core sources.
scenario_backup_paths() {
    jq -r '.core.sources[]' "$(fixture_file)"
}

scenario_cases() {
    fixture_cases
}

scenario_hooks() {
    cat <<'EOF'
pre examples/actions/paperless.json safe 120 true
post examples/actions/paperless-post.json non_retryable 120 true
EOF
}

# scenario_policy: only the Paperless aliases (paths, principal, checks,
# endpoint) of examples/policies/linux-host.yaml, unchanged.
scenario_policy() {
    local policy=$PAPERLESS_STATE_DIR/policy.yaml alias
    mkdir -p "$PAPERLESS_STATE_DIR"
    awk '
        /^[[:space:]]*#/ || /^[[:space:]]*$/ { next }
        /^version:/ { print; next }
        /^[A-Za-z]+:/ { section = $0; printed = 0; keep = 0; next }
        /^  [A-Za-z0-9_-]+:/ {
            name = $1
            sub(/:$/, "", name)
            keep = (name ~ /^paperless-/ || name == "muckisafe-backup")
            if (keep && !printed) { print section; printed = 1 }
        }
        keep { print }
    ' "$REPO_ROOT/$(jq -r '.policy' "$(fixture_file)")" >"$policy"
    for alias in paperless-data paperless-media paperless-export paperless-consume muckisafe-backup \
        paperless-data-required-readable paperless-media-required-readable \
        paperless-export-required-readable paperless-consume-required-readable paperless-web; do
        grep -q "^  $alias:" "$policy" || die "derived Paperless policy misses $alias"
    done
    echo "$policy"
}

# --- helpers --------------------------------------------------------------------

paperless_compose() {
    PAPERLESS_E2E_ENV_DIR=$PAPERLESS_STATE_DIR docker compose -p "$PAPERLESS_PROJECT" \
        -f "$E2E_DIR/fixtures/paperless/compose.yaml" "$@"
}

# paperless_case_value CASE JQ_PATH: a scalar of the case ("" when absent).
paperless_case_value() {
    fixture_case "$1" | jq -r "($2) // empty"
}

paperless_roots() {
    jq -r '.core.sources[]' "$(fixture_file)"
}

paperless_web_url() {
    echo "$(jq -r '.policyOrigin' "$(fixture_file)")/"
}

# paperless_web_probe: "STATUS LOCATION" of GET / on the policy endpoint
# (no redirect is followed; LOCATION is empty without the header).
paperless_web_probe() {
    curl -sS -o /dev/null -D - --max-time 10 "$(paperless_web_url)" 2>/dev/null | tr -d '\r' | awk '
        NR == 1 { status = $2 }
        tolower($1) == "location:" { location = $2 }
        END { print status " " location }'
}

paperless_web_is() {
    [ "$(paperless_web_probe)" = "$1 $2" ]
}

paperless_container_running() {
    local id
    id=$(paperless_compose ps -q "$1" 2>/dev/null)
    [ -n "$id" ] && [ "$(docker inspect -f '{{.State.Running}}' "$id" 2>/dev/null)" = true ]
}

# paperless_snapshot_count: file-backup snapshots in the scenario
# repository (--no-lock: it is read-only while coreFails is injected).
paperless_snapshot_count() {
    local json
    json=$(restic_in_app snapshots --json --no-lock)
    jq 'length' <<<"$json"
}

# --- secrets and web modes --------------------------------------------------------

# Fresh secrets for every run; nothing from a real Paperless installation.
paperless_write_secrets() {
    mkdir -p "$PAPERLESS_STATE_DIR"
    (
        umask 077
        cat >"$PAPERLESS_STATE_DIR/secrets.env" <<EOF
DB_PASSWORD=$(random_hex 16)
DB_ROOT_PASSWORD=$(random_hex 16)
SECRET_KEY=$(random_hex 32)
ADMIN_PASSWORD=$(random_hex 12)
EOF
        cat >"$PAPERLESS_STATE_DIR/db.env" <<EOF
MARIADB_PASSWORD=$(paperless_secret DB_PASSWORD)
MARIADB_ROOT_PASSWORD=$(paperless_secret DB_ROOT_PASSWORD)
EOF
    )
}

paperless_secret() {
    sed -n "s/^$1=//p" "$PAPERLESS_STATE_DIR/secrets.env"
}

# paperless_write_web_env login|autologin
paperless_write_web_env() {
    (
        umask 077
        {
            echo "PAPERLESS_DBPASS=$(paperless_secret DB_PASSWORD)"
            echo "PAPERLESS_SECRET_KEY=$(paperless_secret SECRET_KEY)"
            echo "PAPERLESS_ADMIN_USER=$PAPERLESS_ADMIN_USER"
            echo "PAPERLESS_ADMIN_PASSWORD=$(paperless_secret ADMIN_PASSWORD)"
            [ "$1" != autologin ] || echo "PAPERLESS_AUTO_LOGIN_USERNAME=$PAPERLESS_ADMIN_USER"
        } >"$PAPERLESS_STATE_DIR/web.env"
    )
}

# Web state: web-env holds the environment the Paperless web container was
# created with (login|autologin); web-foreign exists while the responder
# owns the port.
paperless_web_env_mode() {
    cat "$PAPERLESS_STATE_DIR/web-env" 2>/dev/null || echo none
}

paperless_web_in_start_state() {
    [ "$(paperless_web_env_mode)" = login ] && [ ! -f "$PAPERLESS_STATE_DIR/web-foreign" ]
}

# paperless_web_start login|autologin: the real Paperless web in that mode.
paperless_web_start() {
    local mode=$1
    docker rm -f "$PAPERLESS_FOREIGN_CONTAINER" >/dev/null 2>&1 || true
    rm -f "$PAPERLESS_STATE_DIR/web-foreign"
    if [ "$(paperless_web_env_mode)" = "$mode" ]; then
        paperless_compose up -d --wait --wait-timeout 600 --no-deps webserver
    else
        paperless_write_web_env "$mode"
        paperless_compose up -d --wait --wait-timeout 600 --no-deps --force-recreate webserver
        echo "$mode" >"$PAPERLESS_STATE_DIR/web-env"
    fi
    if [ "$mode" = autologin ]; then
        wait_for 300 "Paperless web answers 200" paperless_web_is 200 ""
    else
        wait_for 300 "Paperless web redirects to its login" paperless_web_is 302 "$PAPERLESS_LOGIN_LOCATION"
    fi
}

# paperless_web_foreign LOCATION: Paperless web stopped; a responder from the
# pinned Paperless image answers / with 302 LOCATION on the policy port,
# like the reference test's fake (other paths: 404).
paperless_web_foreign() {
    local location=$1 code
    read -r -d '' code <<'EOF' || true
import http.server, sys

class Handler(http.server.BaseHTTPRequestHandler):
    def do_GET(self):
        if self.path != "/":
            self.send_response(404)
        else:
            self.send_response(302)
            self.send_header("Location", sys.argv[1])
        self.send_header("Content-Length", "0")
        self.end_headers()

http.server.HTTPServer(("0.0.0.0", 8000), Handler).serve_forever()
EOF
    paperless_compose stop webserver
    touch "$PAPERLESS_STATE_DIR/web-foreign"
    docker rm -f "$PAPERLESS_FOREIGN_CONTAINER" >/dev/null 2>&1 || true
    docker run -d --name "$PAPERLESS_FOREIGN_CONTAINER" --label muwa-e2e=paperless \
        -p 127.0.0.1:8091:8000 --entrypoint python3 "$PAPERLESS_WEBSERVER_IMAGE" -c "$code" "$location" >/dev/null
    wait_for 120 "foreign redirect to $location" paperless_web_is 302 "$location"
}

# paperless_web_for_case CASE: the fixture's web state of the case.
paperless_web_for_case() {
    local status location
    status=$(paperless_case_value "$1" '.web.status')
    location=$(paperless_case_value "$1" '.web.location')
    if [ "$status" = 200 ] && [ -z "$location" ]; then
        paperless_web_start autologin
        paperless_assert_body_limit
    elif [ "$status" = 302 ] && [ "$location" = "$PAPERLESS_LOGIN_LOCATION" ]; then
        paperless_web_start login
    elif [ "$status" = 302 ] && [ -n "$location" ]; then
        paperless_web_foreign "$location"
    else
        die "unsupported web state in fixture case '$1': $status $location"
    fi
}

# The policy reads at most maximumBodyBytes of the 200 page; a larger
# Paperless index would fail the probe for the wrong reason.
paperless_assert_body_limit() {
    local limit size
    limit=$(awk '/^  paperless-web:/ { inside = 1; next } inside && /^  [^ ]/ { inside = 0 }
        inside && /maximumBodyBytes:/ { print $2 }' "$AGENT_POLICY")
    size=$(curl -sS -o /dev/null -w '%{size_download}' --max-time 10 "$(paperless_web_url)")
    [ "${size%.*}" -le "${limit:-65536}" ] ||
        die "the Paperless 200 page has $size bytes, more than maximumBodyBytes ${limit:-65536} of paperless-web"
}

# --- seed -------------------------------------------------------------------------

# paperless_seed_entries: "KIND PATH MODE UID GID BASE64_CONTENT" per seed
# entry, directories first (parents before children, as in the fixture).
paperless_seed_entries() {
    jq -r '(.seed.directories[] | ["d", .path, .mode, .uid, .gid, ""]),
           (.seed.files[] | ["f", .path, .mode, .uid, .gid, (.content | @base64)]) | @tsv' "$(fixture_file)"
}

# paperless_seed_apply [PREFIX]: creates the seed entries (all, or PREFIX and
# everything below it) with the fixture's content, owner and mode.
paperless_seed_apply() {
    local prefix=${1:-} kind path mode uid gid content target
    while IFS=$'\t' read -r kind path mode uid gid content; do
        if [ -n "$prefix" ]; then
            case $path in "$prefix"|"$prefix"/*) ;; *) continue ;; esac
        fi
        target=$PAPERLESS_BASE/$path
        if [ "$kind" = d ]; then
            install -d -o "$uid" -g "$gid" -m "$mode" "$target"
        else
            base64 -d <<<"$content" >"$target"
            chown "$uid:$gid" "$target"
            chmod "$mode" "$target"
        fi
    done < <(paperless_seed_entries)
}

paperless_seed_mode() {
    jq -r --arg path "$1" '[.seed.directories[], .seed.files[]] | map(select(.path == $path)) | .[0].mode // empty' "$(fixture_file)"
}

paperless_manifest_name() {
    printf '%s' "$1" | tr -c 'A-Za-z0-9_.-' '_'
}

# paperless_manifest_save NAME: manifest of the four policy roots.
paperless_manifest_save() {
    local roots
    roots=$(paperless_roots | tr '\n' ' ')
    # shellcheck disable=SC2086 # the roots are fixed paths without spaces
    manifest_save "$(paperless_manifest_name "$1")" $roots
}

# paperless_manifest_equal_seed NAME: the saved manifest equals the seed's.
paperless_manifest_equal_seed() {
    manifest_equal seed "$(paperless_manifest_name "$1")"
}

# The fixture's policy roots must be exactly PAPERLESS_BASE/<dir>.
paperless_assert_roots() {
    jq -e --arg base "$PAPERLESS_BASE" \
        '(.policyRoots | to_entries | all(.key == ($base + "/" + .value)))
         and ((.policyRoots | keys | sort) == (.core.sources | sort))' "$(fixture_file)" >/dev/null ||
        die "fixture policy roots are not $PAPERLESS_BASE/<dir> or differ from the core sources"
}

# --- legacy hook ----------------------------------------------------------------

# Frozen decision: paperless_pre.sh is excluded, never repaired or invoked.
paperless_assert_no_legacy_hook() {
    local forbidden file mark
    while IFS= read -r forbidden; do
        for file in "$AGENT_POLICY" "$REPO_ROOT/$(jq -r '.actions.pre' "$(fixture_file)")" \
            "$REPO_ROOT/$(jq -r '.actions.post' "$(fixture_file)")"; do
            if grep -qF -- "$forbidden" "$file"; then
                log "assertion failed: $file references the legacy hook ($forbidden)"
                return 1
            fi
        done
    done < <(jq -r '.legacyHook.forbiddenInPolicyAndActions[]' "$(fixture_file)")
    mark=$(cat "$PAPERLESS_STATE_DIR/case-mark" 2>/dev/null || true)
    [ -n "$mark" ] || return 0
    assert_not_contains "$(agent_journal_since "$mark")" "paperless_pre.sh" "legacy hook in the agent journal"
}

# --- interface: service -----------------------------------------------------------

# scenario_up: documented start state of contracts/paperless.json.
scenario_up() {
    local service
    [ "$(paperless_web_url)" = "http://127.0.0.1:8091/" ] ||
        die "fixture policyOrigin $(paperless_web_url) is not the published port of compose.yaml"
    paperless_write_secrets
    paperless_write_web_env login
    paperless_compose pull --quiet
    paperless_compose up -d --wait --wait-timeout 900
    echo login >"$PAPERLESS_STATE_DIR/web-env"
    rm -f "$PAPERLESS_STATE_DIR/web-foreign"
    for service in webserver paperless-db broker gotenberg tika; do
        paperless_container_running "$service" || die "Paperless service $service is not running"
    done
    wait_for 300 "Paperless web redirects to its login" paperless_web_is 302 "$PAPERLESS_LOGIN_LOCATION"
    assert_eq "$(paperless_compose exec -T broker valkey-cli ping | tr -d '\r')" PONG "Valkey ping"
    paperless_compose exec -T paperless-db healthcheck.sh --connect --innodb_initialized
    paperless_assert_no_legacy_hook
}

# paperless_identity PATH: device and inode, so cleanup only ever removes
# the directory this harness created (never a same-named replacement).
paperless_identity() {
    stat -c '%d:%i' "$1"
}

# paperless_owned NAME PATH: PATH is the directory recorded in marker NAME.
paperless_owned() {
    [ -f "$PAPERLESS_STATE_DIR/$1" ] && [ -d "$2" ] && [ ! -L "$2" ] &&
        [ "$(cat "$PAPERLESS_STATE_DIR/$1")" = "$(paperless_identity "$2")" ]
}

# scenario_prepare_host: PAPERLESS_BASE (the stack's bind source) must exist
# before docker compose up. Created here with an identity marker; an existing
# PAPERLESS_BASE is used only when it is an empty real directory, and is then
# never removed.
scenario_prepare_host() {
    mkdir -p "$PAPERLESS_STATE_DIR"
    if [ -L /data ] || [ -L "$PAPERLESS_BASE" ]; then
        die "/data or $PAPERLESS_BASE is a symlink"
    fi
    if [ ! -e /data ]; then
        install -d -o root -g root -m 0755 /data
        paperless_identity /data >"$PAPERLESS_STATE_DIR/data-created"
    fi
    if [ ! -e "$PAPERLESS_BASE" ]; then
        install -d -o root -g root -m 0755 "$PAPERLESS_BASE"
        paperless_identity "$PAPERLESS_BASE" >"$PAPERLESS_STATE_DIR/base-created"
    elif ! paperless_owned base-created "$PAPERLESS_BASE"; then
        [ -d "$PAPERLESS_BASE" ] || die "$PAPERLESS_BASE is not a directory"
        [ -z "$(ls -A "$PAPERLESS_BASE")" ] || die "$PAPERLESS_BASE already has content that this harness did not create"
    fi
    paperless_identity "$PAPERLESS_BASE" >"$PAPERLESS_STATE_DIR/base-used"
}

# scenario_down: the stack with its volumes, the helper containers, the
# seeded roots and the directories scenario_prepare_host created (identity
# bound; nothing else is removed).
scenario_down() {
    local ids root
    docker rm -f "$PAPERLESS_STALL_CONTAINER" "$PAPERLESS_FOREIGN_CONTAINER" >/dev/null 2>&1 || true
    ids=$(docker ps -aq --filter "label=com.docker.compose.project=$PAPERLESS_PROJECT" 2>/dev/null || true)
    # shellcheck disable=SC2086 # container ids
    [ -z "$ids" ] || docker rm -f -v $ids >/dev/null 2>&1 || true
    ids=$(docker volume ls -q --filter "label=com.docker.compose.project=$PAPERLESS_PROJECT" 2>/dev/null || true)
    # shellcheck disable=SC2086 # volume names
    [ -z "$ids" ] || docker volume rm -f $ids >/dev/null 2>&1 || true
    ids=$(docker network ls -q --filter "label=com.docker.compose.project=$PAPERLESS_PROJECT" 2>/dev/null || true)
    # shellcheck disable=SC2086 # network ids
    [ -z "$ids" ] || docker network rm $ids >/dev/null 2>&1 || true
    if [ -f "$PAPERLESS_STATE_DIR/roots-seeded" ] && paperless_owned base-used "$PAPERLESS_BASE"; then
        while IFS= read -r root; do
            rm -rf -- "${root:?}"
        done < <(paperless_roots)
        rm -f "$PAPERLESS_STATE_DIR/roots-seeded"
    fi
    if paperless_owned base-created "$PAPERLESS_BASE"; then
        rmdir "$PAPERLESS_BASE" 2>/dev/null || true
    fi
    if paperless_owned data-created /data; then
        rmdir /data 2>/dev/null || true
    fi
    rm -f "$PAPERLESS_STATE_DIR/base-created" "$PAPERLESS_STATE_DIR/base-used" "$PAPERLESS_STATE_DIR/data-created"
}

# scenario_seed: the fixture seed below the policy roots and its manifest,
# only inside the PAPERLESS_BASE scenario_prepare_host checked.
scenario_seed() {
    local root
    paperless_assert_roots
    paperless_owned base-used "$PAPERLESS_BASE" || die "$PAPERLESS_BASE is not the directory scenario_prepare_host prepared"
    touch "$PAPERLESS_STATE_DIR/roots-seeded"
    while IFS= read -r root; do
        rm -rf -- "${root:?}"
    done < <(paperless_roots)
    paperless_seed_apply
    paperless_manifest_save seed
}

# --- interface: cases -------------------------------------------------------------

# paperless_restore_leftovers: a case aborted before its reset (the
# orchestrator then only runs inject_reset_all) leaves its injection behind;
# the next case starts from the seed again.
paperless_restore_leftovers() {
    paperless_manifest_save "before-inject"
    paperless_manifest_equal_seed "before-inject" 2>/dev/null && return 0
    log "restoring the fixture seed left changed by an aborted case"
    paperless_remove_symlink_injections
    paperless_seed_apply
    paperless_manifest_save "before-inject"
    paperless_manifest_equal_seed "before-inject"
}

# Removes the fixture's injected symlinks (only exactly those).
paperless_remove_symlink_injections() {
    local path target
    while IFS=$'\t' read -r path target; do
        [ -L "$PAPERLESS_BASE/$path" ] && [ "$(readlink "$PAPERLESS_BASE/$path")" = "$target" ] &&
            rm -f -- "$PAPERLESS_BASE/$path"
    done < <(jq -r '.scenarios[].inject.symlink // empty | [.path, .target] | @tsv' "$(fixture_file)")
    return 0
}

scenario_inject() {
    local name=$1 unreadable remove link_path link_target runs
    rm -f "$PAPERLESS_STATE_DIR"/case-*
    paperless_restore_leftovers
    journal_mark >"$PAPERLESS_STATE_DIR/case-mark"
    paperless_snapshot_count >"$PAPERLESS_STATE_DIR/case-snapshots"
    paperless_web_for_case "$name"

    unreadable=$(paperless_case_value "$name" '.inject.unreadable')
    if [ -n "$unreadable" ]; then
        [ -e "$PAPERLESS_BASE/$unreadable" ] || die "inject: $unreadable is not part of the seed"
        chmod 0700 "$PAPERLESS_BASE/$unreadable"
    fi
    remove=$(paperless_case_value "$name" '.inject.remove')
    if [ -n "$remove" ]; then
        [ -e "$PAPERLESS_BASE/$remove" ] || [ -L "$PAPERLESS_BASE/$remove" ] || die "inject: $remove is not part of the seed"
        rm -rf -- "${PAPERLESS_BASE:?}/$remove"
    fi
    link_path=$(paperless_case_value "$name" '.inject.symlink.path')
    if [ -n "$link_path" ]; then
        link_target=$(paperless_case_value "$name" '.inject.symlink.target')
        getfacl -cp "$link_target" >"$PAPERLESS_STATE_DIR/case-target-acl" 2>/dev/null || true
        stat -c '%a %u:%g' "$link_target" >>"$PAPERLESS_STATE_DIR/case-target-acl"
        rm -rf -- "${PAPERLESS_BASE:?}/$link_path"
        ln -s -- "$link_target" "$PAPERLESS_BASE/$link_path"
    fi
    if [ "$(paperless_case_value "$name" '.inject.coreFails')" = true ]; then
        core_break
    fi
    runs=$(paperless_case_value "$name" '.inject.runs')
    if [ "${runs:-1}" -gt 1 ]; then
        paperless_repeat_runs "$name" $((runs - 1))
    fi
}

# paperless_repeat_runs CASE COUNT: COUNT complete backups (pre, core, post)
# before the orchestrator's own run, each asserted like the final one.
paperless_repeat_runs() {
    local name=$1 count=$2 i
    : >"$PAPERLESS_STATE_DIR/case-earlier-runs"
    for i in $(seq 1 "$count"); do
        backup_trigger
        assert_case "$name"
        run_json | jq -r '.id' >>"$PAPERLESS_STATE_DIR/case-earlier-runs"
        log "repeated run $i of '$name' asserted"
    done
}

scenario_assert_effects() {
    local name=$1 expected_status expected_location core runs before after
    expected_status=$(paperless_case_value "$name" '.web.status')
    expected_location=$(paperless_case_value "$name" '.web.location')
    core=$(paperless_case_value "$name" '.expect.core')
    runs=$(paperless_case_value "$name" '.inject.runs')
    runs=${runs:-1}

    paperless_assert_stack_state "$expected_status" "$expected_location"
    assert_eq "$(paperless_web_probe)" "$expected_status $expected_location" "web answer of the case"
    paperless_assert_no_legacy_hook

    before=$(cat "$PAPERLESS_STATE_DIR/case-snapshots" 2>/dev/null || true)
    after=$(paperless_snapshot_count)
    case $core in
        readable|succeeded)
            paperless_assert_core_readable
            if [ -n "$before" ]; then
                assert_eq "$after" $((before + runs)) "file-backup snapshots created by the case"
            else
                [ "$after" -ge 1 ] || { log "assertion failed: no file-backup snapshot"; return 1; }
            fi
            ;;
        failed|skipped)
            [ -z "$before" ] || assert_eq "$after" "$before" "file-backup snapshots after a $core core"
            ;;
    esac
    paperless_assert_earlier_runs
    paperless_assert_symlink_target "$name"
}

# All Paperless services keep running; for a foreign redirect only the
# web server is replaced by the responder.
paperless_assert_stack_state() {
    local service
    for service in paperless-db broker gotenberg tika; do
        paperless_container_running "$service" || { log "assertion failed: Paperless $service is not running"; return 1; }
    done
    if [ "$1" = 302 ] && [ "$2" != "$PAPERLESS_LOGIN_LOCATION" ]; then
        [ "$(docker inspect -f '{{.State.Running}}' "$PAPERLESS_FOREIGN_CONTAINER" 2>/dev/null)" = true ] ||
            { log "assertion failed: the foreign responder is not running"; return 1; }
    else
        paperless_container_running webserver || { log "assertion failed: Paperless web is not running"; return 1; }
    fi
}

# core: readable — the backup principal (www-data in the MuckiSafe app, the
# same read-only mount the core reads) can read every seeded file.
paperless_assert_core_readable() {
    local roots
    roots=$(paperless_roots | tr '\n' ' ')
    # shellcheck disable=SC2016,SC2086 # $@ expands in the container; fixed root paths
    stack exec -T -u www-data app sh -c 'for root in "$@"; do find "$root" -type f -exec cat {} + >/dev/null || exit 1; done' \
        sh $roots
}

paperless_assert_earlier_runs() {
    local id
    [ -f "$PAPERLESS_STATE_DIR/case-earlier-runs" ] || return 0
    while IFS= read -r id; do
        assert_eq "$(sql "SELECT status FROM muwa_agent_backup_run WHERE id = UNHEX('$id')")" succeeded "earlier run $id"
        [ "$id" != "$(run_json | jq -r '.id')" ] || { log "assertion failed: the repeated run is the earlier run"; return 1; }
    done <"$PAPERLESS_STATE_DIR/case-earlier-runs"
}

# The symlink is listed, never followed: still a link, target ACL and mode
# unchanged.
paperless_assert_symlink_target() {
    local link_path link_target
    link_path=$(paperless_case_value "$1" '.inject.symlink.path')
    [ -n "$link_path" ] || return 0
    link_target=$(paperless_case_value "$1" '.inject.symlink.target')
    [ -L "$PAPERLESS_BASE/$link_path" ] || { log "assertion failed: $link_path is no longer a symlink"; return 1; }
    assert_eq "$(readlink "$PAPERLESS_BASE/$link_path")" "$link_target" "symlink target"
    assert_eq "$({ getfacl -cp "$link_target" 2>/dev/null || true; stat -c '%a %u:%g' "$link_target"; })" \
        "$(cat "$PAPERLESS_STATE_DIR/case-target-acl")" "ACL and mode of the symlink target"
}

# scenario_reset CASE: undo exactly the case's injection, return to the
# documented start state and prove the fixture end state (seed tree, modes
# and ACLs exactly as before; nothing to remove).
scenario_reset() {
    local name=$1 unreadable remove link_path link_target
    scenario_stall off
    unreadable=$(paperless_case_value "$name" '.inject.unreadable')
    [ -z "$unreadable" ] || chmod "$(paperless_seed_mode "$unreadable")" "$PAPERLESS_BASE/$unreadable"
    remove=$(paperless_case_value "$name" '.inject.remove')
    [ -z "$remove" ] || paperless_seed_apply "$remove"
    link_path=$(paperless_case_value "$name" '.inject.symlink.path')
    if [ -n "$link_path" ]; then
        link_target=$(paperless_case_value "$name" '.inject.symlink.target')
        [ -L "$PAPERLESS_BASE/$link_path" ] && [ "$(readlink "$PAPERLESS_BASE/$link_path")" = "$link_target" ] &&
            rm -f -- "$PAPERLESS_BASE/$link_path"
    fi
    if [ "$(paperless_case_value "$name" '.inject.coreFails')" = true ]; then
        core_repair
    fi
    if paperless_web_in_start_state && paperless_web_is 302 "$PAPERLESS_LOGIN_LOCATION"; then
        :
    else
        paperless_web_start login
    fi
    rm -f "$PAPERLESS_STATE_DIR"/case-*
    paperless_manifest_save "end-$name"
    paperless_manifest_equal_seed "end-$name"
}

# scenario_stall on|off: the first host effect of the first hooked phase is
# the pre step 1 walk of paperless-data, which opens every file below the
# root as the backup principal. A fanotify listener (privileged container of
# the pinned Paperless image) holds the FAN_OPEN_PERM event of the first
# seeded data file unanswered; "off" removes the container, the kernel then
# allows every held open and the step continues.
scenario_stall() {
    case $1 in
        on) paperless_stall_on ;;
        off) docker rm -f "$PAPERLESS_STALL_CONTAINER" >/dev/null 2>&1 || true ;;
        *) die "scenario_stall on|off" ;;
    esac
}

paperless_stall_on() {
    local file code
    file=$(jq -r '[.seed.files[] | select(.path | startswith("data/"))][0].path | sub("^data/"; "")' "$(fixture_file)")
    read -r -d '' code <<'EOF' || true
import ctypes, os, struct, sys

libc = ctypes.CDLL(None, use_errno=True)
libc.fanotify_mark.argtypes = [ctypes.c_int, ctypes.c_uint, ctypes.c_uint64, ctypes.c_int, ctypes.c_char_p]
FAN_CLOEXEC, FAN_CLASS_CONTENT = 0x01, 0x04
FAN_MARK_ADD, FAN_OPEN_PERM, AT_FDCWD = 0x01, 0x00010000, -100

group = libc.fanotify_init(FAN_CLOEXEC | FAN_CLASS_CONTENT, os.O_RDONLY)
if group < 0:
    sys.exit("fanotify_init: " + os.strerror(ctypes.get_errno()))
if libc.fanotify_mark(group, FAN_MARK_ADD, FAN_OPEN_PERM, AT_FDCWD, sys.argv[1].encode()) != 0:
    sys.exit("fanotify_mark: " + os.strerror(ctypes.get_errno()))
print("armed", flush=True)
while True:
    data = os.read(group, 4096)
    offset = 0
    while offset + 24 <= len(data):
        length, _, _, _, mask, fd, pid = struct.unpack_from("=IBBHQii", data, offset)
        print("holding the open of pid %d until the stall ends" % pid, flush=True)
        offset += length
EOF
    [ -f "$PAPERLESS_BASE/data/$file" ] || die "stall: $PAPERLESS_BASE/data/$file does not exist"
    docker rm -f "$PAPERLESS_STALL_CONTAINER" >/dev/null 2>&1 || true
    docker run -d --name "$PAPERLESS_STALL_CONTAINER" --label muwa-e2e=paperless --privileged --user 0:0 --network none \
        -v "$PAPERLESS_BASE/data:/stall:ro" --entrypoint python3 "$PAPERLESS_WEBSERVER_IMAGE" \
        -c "$code" "/stall/$file" >/dev/null
    wait_for 60 "fanotify stall armed" paperless_stall_armed ||
        { docker logs "$PAPERLESS_STALL_CONTAINER" >&2 2>&1 || true; return 1; }
}

paperless_stall_armed() {
    local logs
    logs=$(docker logs "$PAPERLESS_STALL_CONTAINER" 2>&1) || return 1
    grep -qx armed <<<"$logs"
}

scenario_collect_logs() {
    local dir=$1
    [ -f "$PAPERLESS_STATE_DIR/web.env" ] || return 0
    paperless_compose ps -a >"$dir/paperless-ps.txt" 2>&1 || true
    paperless_compose logs --no-color --timestamps >"$dir/paperless.log" 2>&1 || true
    docker logs "$PAPERLESS_STALL_CONTAINER" >"$dir/paperless-stall.log" 2>&1 || true
    docker logs "$PAPERLESS_FOREIGN_CONTAINER" >"$dir/paperless-foreign.log" 2>&1 || true
}
