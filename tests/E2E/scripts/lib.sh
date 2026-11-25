# shellcheck shell=bash
# Shared helpers of the Linux service E2E (Task 32). Sourced by the
# orchestrator, the step scripts and every scenario; never executed alone.
#
# Bash 3.2 compatible (the dry run and the selftest also run on macOS):
# no mapfile, no associative arrays, no ${var,,}.
#
# The constants below are used by the sourcing scripts and scenarios.
# shellcheck disable=SC2034

E2E_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
REPO_ROOT=$(cd -- "$E2E_DIR/../.." && pwd)
E2E_DRY_RUN=${E2E_DRY_RUN:-0}

# Host layout of one E2E run. The host is changed: run only on a disposable
# runner or VM.
WORK_DIR=${MUWA_E2E_WORK_DIR:-/srv/muwa-e2e}
OUT_DIR=${OUT_DIR:-$E2E_DIR/out}
readonly COMPOSE_PROJECT=muwa-e2e
readonly HTTP_PORT=18080
readonly HTTPS_HOST=muwa-e2e.test
readonly HTTPS_PORT=18443
readonly WAKEUP_HOST_DIR=/var/lib/mucki-agent/wakeup
readonly AGENT_CONFIG=/etc/mucki-agent/config.yaml
readonly AGENT_SECRET=/etc/mucki-agent/secret
readonly AGENT_POLICY=/etc/mucki-agent/policy.yaml
readonly AGENT_STATE=/var/lib/mucki-agent/state
readonly AGENT_UNIT=mucki-agent.service
readonly BACKUP_REPOS_HOST_DIR=$WORK_DIR/repos
readonly CONTAINER_REPOS_DIR=/var/backups/muckisafe

# --- logging ------------------------------------------------------------------

log() {
    printf '%s %s\n' "$(date -u +%H:%M:%S)" "$*" >&2
}

die() {
    log "ERROR: $*"
    exit 1
}

is_dry_run() {
    [ "$E2E_DRY_RUN" = 1 ]
}

# plan STEP DESCRIPTION: announces a step; the dry run only prints the plan.
plan() {
    echo "plan $1 - $2"
}

# --- JUnit --------------------------------------------------------------------

JUNIT_FILE=""
JUNIT_SUITE=""
JUNIT_CASES=""
JUNIT_TESTS=0
JUNIT_FAILURES=0
JUNIT_SKIPPED=0

# xml_escape TEXT: XML attribute value; control characters (ANSI colours of
# logs) are dropped, because they are not valid in XML 1.0.
xml_escape() {
    printf '%s' "$1" | tr -d '\000-\010\013\014\016-\037' |
        sed -e 's/&/\&amp;/g' -e 's/</\&lt;/g' -e 's/>/\&gt;/g' -e 's/"/\&quot;/g'
}

junit_begin() {
    JUNIT_SUITE=$1
    JUNIT_FILE=$2
    JUNIT_CASES=""
    JUNIT_TESTS=0
    JUNIT_FAILURES=0
    JUNIT_SKIPPED=0
}

# junit_case CLASS NAME SECONDS [failure|skipped MESSAGE]
junit_case() {
    local class name seconds kind message body=""
    class=$(xml_escape "$1")
    name=$(xml_escape "$2")
    seconds=$3
    kind=${4:-}
    message=$(xml_escape "${5:-}")
    JUNIT_TESTS=$((JUNIT_TESTS + 1))
    case $kind in
        failure) JUNIT_FAILURES=$((JUNIT_FAILURES + 1)); body="<failure message=\"$message\"/>" ;;
        skipped) JUNIT_SKIPPED=$((JUNIT_SKIPPED + 1)); body="<skipped message=\"$message\"/>" ;;
    esac
    JUNIT_CASES="$JUNIT_CASES  <testcase classname=\"$class\" name=\"$name\" time=\"$seconds\">$body</testcase>
"
}

junit_write() {
    [ -n "$JUNIT_FILE" ] || return 0
    mkdir -p "$(dirname -- "$JUNIT_FILE")"
    {
        echo '<?xml version="1.0" encoding="UTF-8"?>'
        echo "<testsuite name=\"$(xml_escape "$JUNIT_SUITE")\" tests=\"$JUNIT_TESTS\" failures=\"$JUNIT_FAILURES\" skipped=\"$JUNIT_SKIPPED\">"
        printf '%s' "$JUNIT_CASES"
        echo '</testsuite>'
    } >"$JUNIT_FILE"
}

# check CLASS NAME FUNCTION ARGS...: runs one test case in a subshell, records
# it in the JUnit report and stores its status in CHECK_STATUS. It always
# returns 0 and must be called as a plain command: in an `||`, `&&`, `if` or
# `!` context bash ignores `set -e` for everything inside, also in the
# subshell, and failed assertions would pass silently. The case reads stdin
# from /dev/null, so docker exec cannot swallow a caller's `while read` input.
CHECK_STATUS=0
check() {
    local class=$1 name=$2 start status log_file
    shift 2
    if is_dry_run; then
        echo "case $class :: $name"
        junit_case "$class" "$name" 0
        CHECK_STATUS=0
        return 0
    fi
    log "--- $class :: $name"
    log_file=$OUT_DIR/cases/$(printf '%s' "$class-$name" | tr -c 'A-Za-z0-9_.-' '_').log
    mkdir -p "$OUT_DIR/cases"
    start=$SECONDS
    set +e
    ( set -euo pipefail; "$@" ) </dev/null >"$log_file" 2>&1
    status=$?
    set -e
    CHECK_STATUS=$status
    if [ "$status" -eq 0 ]; then
        junit_case "$class" "$name" $((SECONDS - start))
        log "ok - $name"
    else
        junit_case "$class" "$name" $((SECONDS - start)) failure "$(tail -n 20 "$log_file")"
        log "not ok - $name (see $log_file)"
        tail -n 20 "$log_file" >&2
    fi
    return 0
}

# --- waiting ------------------------------------------------------------------

# wait_for SECONDS DESCRIPTION COMMAND...
wait_for() {
    local seconds=$1 description=$2 deadline
    shift 2
    deadline=$((SECONDS + seconds))
    until "$@"; do
        [ "$SECONDS" -lt "$deadline" ] || { log "timed out after ${seconds}s: $description"; return 1; }
        sleep 1
    done
}

# --- pins and fixtures ----------------------------------------------------------

load_pins() {
    set -a
    # shellcheck source=../images.lock
    . "$E2E_DIR/images.lock"
    # shellcheck source=../versions.env
    . "$E2E_DIR/versions.env"
    set +a
}

fixture_file() {
    echo "$E2E_DIR/fixtures/$SCENARIO_NAME/fixture.json"
}

# fixture_cases: every case name of the scenario's fixture, one per line.
fixture_cases() {
    jq -r '.scenarios[].name' "$(fixture_file)"
}

# fixture_case NAME: the case object.
fixture_case() {
    jq -ce --arg name "$1" '.scenarios[] | select(.name == $name)' "$(fixture_file)"
}

# fixture_inject NAME [KEY]: the inject object of a case, or one of its keys
# (empty when absent).
fixture_inject() {
    if [ $# -eq 1 ]; then
        fixture_case "$1" | jq -c '.inject // {}'
    else
        fixture_case "$1" | jq -c --arg key "$2" '.inject[$key] // empty'
    fi
}

# expect_phases NAME: normalized phase expectations of a case as
# {"pre": {...}, "post": {...}, "finally": {...}, "core": "..."}. A
# top-level outcome/resultCode/summaryPrefix belongs to SCENARIO_DEFAULT_PHASE.
expect_phases() {
    fixture_case "$1" | jq -c --arg phase "${SCENARIO_DEFAULT_PHASE:-pre}" '
        .expect as $e
        | ($e | with_entries(select(.key == "pre" or .key == "post" or .key == "finally")))
        + (if ($e | has("outcome")) then {($phase): ($e | {outcome, resultCode, summaryPrefix})} else {} end)
        + (if ($e | has("core")) then {core: $e.core} else {} end)'
}

# Default for every scenario; a scenario overrides it for an architecture it
# cannot run natively (and must document why).
scenario_skip_reason() {
    return 0
}

# Optional scenario hook, called before the MuckiSafe stack starts: create
# every path of scenario_source_mounts (and anything else the host needs
# before docker compose up), because the stack never creates bind sources.
scenario_prepare_host() {
    return 0
}

# --- docker compose stack -------------------------------------------------------

# Directory of the MuckiSafe checkout whose docker-compose.yml is the system
# under test (CI: checkout of muckiware/MuckiSafe at the pinned ref).
MUCKISAFE_DIR=${MUWA_E2E_MUCKISAFE_DIR:-$WORK_DIR/muckisafe}

stack() {
    docker compose -p "$COMPOSE_PROJECT" \
        --project-directory "$MUCKISAFE_DIR" \
        -f "$MUCKISAFE_DIR/docker-compose.yml" \
        -f "$E2E_DIR/compose/compose.yaml" \
        -f "$WORK_DIR/sources.override.yml" \
        --env-file "$WORK_DIR/stack.env" "$@"
}

# console ARGS...: bin/console in the app container as www-data (never
# reads the caller's stdin).
console() {
    stack exec -T -u www-data app bin/console "$@" </dev/null
}

# sql QUERY: one query against the MuckiSafe database; tab-separated rows.
sql() {
    # The variables expand inside the db container, not here.
    # shellcheck disable=SC2016
    stack exec -T db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -N -B --raw -u root "$MYSQL_DATABASE"' <<<"$1"
}

# sql_json QUERY: the query must select exactly one JSON_OBJECT/JSON_ARRAYAGG
# column; prints it (empty when there is no row).
sql_json() {
    sql "$1" | head -n 1
}

# --- administration API ---------------------------------------------------------

API_TOKEN=""

api_url() {
    echo "http://127.0.0.1:$HTTP_PORT"
}

api_login() {
    local user password
    user=$(env_value ADMIN_USERNAME)
    password=$(env_value ADMIN_PASSWORD)
    API_TOKEN=$(jq -n --arg u "$user" --arg p "$password" \
        '{grant_type: "password", client_id: "administration", scopes: "write", username: $u, password: $p}' |
        curl -fsS -H 'Content-Type: application/json' --data-binary @- "$(api_url)/api/oauth/token" |
        jq -r '.access_token')
    [ -n "$API_TOKEN" ] && [ "$API_TOKEN" != null ] || die "admin login failed"
}

# api METHOD PATH [JSON]: authenticated admin API call; prints the body and
# fails on HTTP >= 400 (the body goes to stderr then).
api() {
    local method=$1 path=$2 body=${3:-} response status
    [ -n "$API_TOKEN" ] || api_login
    response=$(mktemp)
    if ! status=$(curl -sS -o "$response" -w '%{http_code}' -X "$method" \
        -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -H 'Accept: application/json' \
        ${body:+--data-binary "$body"} "$(api_url)$path"); then
        log "API $method $path: connection failed"
        rm -f "$response"
        return 1
    fi
    if [ "$status" -lt 200 ] || [ "$status" -ge 300 ]; then
        log "API $method $path -> $status: $(head -c 2000 "$response")"
        rm -f "$response"
        return 1
    fi
    cat "$response"
    rm -f "$response"
}

# env_value NAME: a value of the generated stack.env.
env_value() {
    sed -n "s/^$1=//p" "$WORK_DIR/stack.env" | head -n 1 | sed -e "s/^'//" -e "s/'$//"
}

# uuid: a lowercase hex UUID without dashes, as the Shopware API uses it.
uuid() {
    od -An -N16 -tx1 /dev/urandom | tr -d ' \n'
}

# --- host state ---------------------------------------------------------------

# manifest DIR...: stable listing of a tree (path, type, mode, owner, size,
# content hash, ACL) for before/after comparisons; symlinks are listed, never
# followed.
manifest() {
    local dir
    for dir in "$@"; do
        [ -e "$dir" ] || { echo "missing $dir"; continue; }
        find "$dir" -xdev -printf '%p\t%y\t%m\t%u:%g\t%s\t%l\n' | LC_ALL=C sort | while IFS=$'\t' read -r path type mode owner size link; do
            local hash=-
            [ "$type" = f ] && hash=$(sha256sum "$path" | cut -c1-64)
            printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n' "$path" "$type" "$mode" "$owner" "$size" "$hash" "$link" \
                "$(getfacl -cp --skip-base "$path" 2>/dev/null | tr '\n' ',' || true)"
        done
    done
}

# manifest_save NAME DIR...: stores a manifest under OUT_DIR/manifests.
manifest_save() {
    local name=$1
    shift
    mkdir -p "$OUT_DIR/manifests"
    manifest "$@" >"$OUT_DIR/manifests/$name.tsv"
}

# manifest_equal BEFORE AFTER: fails with a diff when two saved manifests
# differ.
manifest_equal() {
    diff -u "$OUT_DIR/manifests/$1.tsv" "$OUT_DIR/manifests/$2.tsv" >&2
}

agent_main_pid() {
    systemctl show -p MainPID --value "$AGENT_UNIT"
}

# journal_mark: prints a mark (systemd.time "@epoch") for agent_journal_since.
# Waits for the next second, so no line of the second before is included.
journal_mark() {
    local now
    now=$(date +%s)
    while [ "$(date +%s)" -eq "$now" ]; do sleep 0.1; done
    echo "@$((now + 1))"
}

# agent_journal_since MARK: agent log lines since the mark.
agent_journal_since() {
    journalctl -u "$AGENT_UNIT" --no-pager -o cat --since "$1"
}
