#!/usr/bin/env bash
# Static self-test of the Linux service E2E harness (Task 32). Needs neither
# root, docker nor Linux, so it runs on every developer machine and in the
# PHP/Go CI before the expensive service E2E:
#
#   tests/E2E/scripts/selftest.sh
#
# Checks: immutable pins (images.lock/versions.env), the scenario interface
# of every service, that every fixture case is covered, the documented
# Bitwarden arm64 exclusion, the dry-run plan of the orchestrator, the
# compose override and the workflow matrix.

# scenario_probe sources lib.sh in a subshell on purpose (it redefines
# E2E_DIR/REPO_ROOT identically); nothing leaks back.
# shellcheck disable=SC2030,SC2031
set -euo pipefail

E2E_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
REPO_ROOT=$(cd -- "$E2E_DIR/../.." && pwd)
readonly SERVICES=(bitwarden jellyfin immich paperless)
readonly ARCHES=(amd64 arm64)
readonly REQUIRED_FUNCTIONS=(
    scenario_images scenario_source_mounts scenario_backup_paths scenario_policy
    scenario_hooks scenario_up scenario_down scenario_seed scenario_cases
    scenario_inject scenario_assert_effects scenario_reset scenario_stall
)
readonly REQUIRED_SCRIPTS=(run-linux-e2e.sh lib.sh setup.sh trigger-backup.sh inject-failure.sh rollback.sh assert-effects.sh cleanup.sh selftest.sh)
FAILURES=0
TMP_DIR=$(mktemp -d)
readonly TMP_DIR
trap 'rm -rf "$TMP_DIR"' EXIT

fail() {
    echo "FAIL: $*" >&2
    FAILURES=$((FAILURES + 1))
}

need() {
    command -v "$1" >/dev/null || { echo "selftest needs $1" >&2; exit 2; }
}

# --- pins ---------------------------------------------------------------------

check_images_lock() {
    local file=$E2E_DIR/images.lock line name ref
    while IFS= read -r line; do
        case $line in ''|'#'*) continue ;; esac
        name=${line%%=*}
        ref=${line#*=}
        [[ $name =~ ^[A-Z][A-Z0-9_]*_IMAGE$ ]] || fail "images.lock: invalid variable name in: $line"
        [[ $ref =~ ^[a-z0-9.-]+(:[0-9]+)?/[a-z0-9._/-]+:[A-Za-z0-9._-]+@sha256:[0-9a-f]{64}$ ]] ||
            fail "images.lock: $name is not an immutable tag@sha256 reference: $ref"
        if [[ $ref == *:latest@* ]]; then fail "images.lock: $name uses latest"; fi
    done <"$file"
}

check_versions_env() {
    local file=$E2E_DIR/versions.env
    if grep -Eq '(<|>|TODO|TBD|\$\{|latest)' <(grep -v '^#' "$file"); then
        fail "versions.env contains a placeholder or latest"
    fi
    # Every *_E2E_VERSION is the tag of the matching *_IMAGE in images.lock.
    local line name version image ref
    while IFS= read -r line; do
        [[ $line =~ ^([A-Z0-9_]+)_E2E_VERSION=(.+)$ ]] || continue
        name=${BASH_REMATCH[1]}
        version=${BASH_REMATCH[2]}
        image=${name}_IMAGE
        ref=$(grep -E "^${image}=" "$E2E_DIR/images.lock" | cut -d= -f2- || true)
        if [ -z "$ref" ]; then
            fail "versions.env: ${name}_E2E_VERSION has no ${image} in images.lock"
            continue
        fi
        [[ $ref == *":${version}@sha256:"* ]] || fail "versions.env: ${name}_E2E_VERSION=$version does not match $ref"
    done <"$file"
    grep -Eq '^MUCKISAFE_COMPOSE_REF=[0-9a-f]{40}$' "$file" ||
        fail "versions.env: MUCKISAFE_COMPOSE_REF must be a full commit hash of muckiware/MuckiSafe"
}

# --- scripts ------------------------------------------------------------------

check_scripts() {
    local script
    for script in "${REQUIRED_SCRIPTS[@]}"; do
        [ -f "$E2E_DIR/scripts/$script" ] || { fail "missing scripts/$script"; continue; }
        bash -n "$E2E_DIR/scripts/$script" || fail "syntax error in scripts/$script"
    done
    for script in run-linux-e2e.sh selftest.sh; do
        [ -x "$E2E_DIR/scripts/$script" ] || fail "scripts/$script is not executable"
    done
    if command -v shellcheck >/dev/null; then
        local helpers=()
        while IFS= read -r script; do helpers+=("$script"); done < <(find "$E2E_DIR/fixtures" -name '*.sh')
        shellcheck -x -P "$E2E_DIR/scripts:$E2E_DIR/scenarios" "$E2E_DIR"/scripts/*.sh "$E2E_DIR"/scenarios/*.sh ${helpers[@]+"${helpers[@]}"} ||
            fail "shellcheck reported findings"
    else
        echo "SKIP: shellcheck not installed"
    fi
}

# --- scenarios ----------------------------------------------------------------

# scenario_probe SERVICE: sources lib and scenario in a subshell and prints
# "fn <name>" per defined interface function, "arches <list>", "skip <arch>
# <reason>" and "case <name>" per case.
scenario_probe() {
    (
        set -euo pipefail
        E2E_DRY_RUN=1
        SCENARIO_NAME=$1
        # shellcheck source=lib.sh
        . "$E2E_DIR/scripts/lib.sh"
        # shellcheck disable=SC1090
        . "$E2E_DIR/scenarios/$1.sh"
        local fn arch reason
        for fn in "${REQUIRED_FUNCTIONS[@]}"; do
            declare -F "$fn" >/dev/null && echo "fn $fn"
        done
        echo "arches ${SCENARIO_ARCHES:-}"
        echo "success ${SCENARIO_SUCCESS_CASE:-}"
        for arch in "${ARCHES[@]}"; do
            reason=$(scenario_skip_reason "$arch")
            [ -z "$reason" ] || echo "skip $arch $reason"
        done
        scenario_cases | sed 's/^/case /'
        scenario_images | sed 's/^/image /'
    )
}

check_scenario() {
    local service=$1 probe fixture missing
    fixture=$E2E_DIR/fixtures/$service/fixture.json
    [ -f "$fixture" ] || { fail "missing fixtures/$service/fixture.json"; return; }
    [ -f "$E2E_DIR/scenarios/$service.sh" ] || { fail "missing scenarios/$service.sh"; return; }
    bash -n "$E2E_DIR/scenarios/$service.sh" || { fail "syntax error in scenarios/$service.sh"; return; }
    if ! probe=$(scenario_probe "$service" 2>"$TMP_DIR/probe.err"); then
        fail "scenarios/$service.sh cannot be sourced: $(tr '\n' ' ' <"$TMP_DIR/probe.err")"
        return
    fi
    local fn
    for fn in "${REQUIRED_FUNCTIONS[@]}"; do
        grep -qx "fn $fn" <<<"$probe" || fail "scenarios/$service.sh does not define $fn"
    done
    # Every fixture case is run, nothing invented.
    jq -r '.scenarios[].name' "$fixture" | sort >"$TMP_DIR/expected"
    sed -n 's/^case //p' <<<"$probe" | sort >"$TMP_DIR/actual"
    missing=$(comm -23 "$TMP_DIR/expected" "$TMP_DIR/actual" | tr '\n' ';')
    [ -z "$missing" ] || fail "scenarios/$service.sh misses fixture cases: $missing"
    missing=$(comm -13 "$TMP_DIR/expected" "$TMP_DIR/actual" | tr '\n' ';')
    [ -z "$missing" ] || fail "scenarios/$service.sh runs cases that are not in the fixture: $missing"
    check_arches "$service" "$probe"
    # Every image a scenario pulls is pinned in images.lock.
    local image success
    while IFS= read -r image; do
        [ -n "$image" ] || continue
        grep -q "^${image}=" "$E2E_DIR/images.lock" || fail "scenarios/$service.sh uses $image, which is not in images.lock"
    done < <(sed -n 's/^image //p' <<<"$probe")
    success=$(sed -n 's/^success //p' <<<"$probe")
    grep -qxF -- "$success" "$TMP_DIR/expected" ||
        fail "scenarios/$service.sh: SCENARIO_SUCCESS_CASE '$success' is not a fixture case"
    if grep -q 'E2E_TODO' "$E2E_DIR/scenarios/$service.sh"; then
        fail "scenarios/$service.sh is not finished (E2E_TODO markers left)"
    fi
}

# Bitwarden's pinned MSSQL image is amd64 only: arm64 is a documented skip,
# every other service runs on both native architectures.
check_arches() {
    local service=$1 probe=$2
    if [ "$service" = bitwarden ]; then
        grep -qx "arches amd64" <<<"$probe" || fail "bitwarden must declare SCENARIO_ARCHES=amd64"
        grep -q "^skip arm64 ." <<<"$probe" || fail "bitwarden must give a skip reason for arm64"
        if grep -q "^skip amd64" <<<"$probe"; then fail "bitwarden must not skip amd64"; fi
        return 0
    fi
    grep -qx "arches amd64 arm64" <<<"$probe" || fail "$service must declare SCENARIO_ARCHES=\"amd64 arm64\""
    if grep -q "^skip " <<<"$probe"; then fail "$service must not skip an architecture"; fi
    return 0
}

check_bitwarden_image_platform() {
    grep -q 'mssql' "$E2E_DIR/images.lock" || return 0
    grep -q 'amd64 only' "$E2E_DIR/scenarios/bitwarden.sh" ||
        fail "scenarios/bitwarden.sh must document why the MSSQL image restricts it to amd64"
}

# --- orchestrator dry run -----------------------------------------------------

check_dry_run() {
    local service arch out junit
    for service in "${SERVICES[@]}"; do
        for arch in "${ARCHES[@]}"; do
            out=$TMP_DIR/dry-$service-$arch
            if ! "$E2E_DIR/scripts/run-linux-e2e.sh" --dry-run --arch "$arch" --scenario "$service" \
                --out "$out" >"$out.log" 2>&1; then
                fail "dry run $service/$arch failed: $(tail -n 5 "$out.log" | tr '\n' ' ')"
                continue
            fi
            junit=$out/junit.xml
            [ -f "$junit" ] || { fail "dry run $service/$arch wrote no junit.xml"; continue; }
            if [ "$service/$arch" = bitwarden/arm64 ]; then
                grep -q '<skipped' "$junit" || fail "bitwarden/arm64 must be reported as skipped"
            else
                if grep -q "<skipped" "$junit"; then fail "$service/$arch must not be skipped"; fi
                for step in preflight stack agent service backup cases resilience transport upgrade facility-only rollback cleanup; do
                    grep -q "^plan $step" "$out.log" || fail "dry run $service/$arch misses plan step $step"
                done
            fi
        done
    done
    if "$E2E_DIR/scripts/run-linux-e2e.sh" --dry-run --arch amd64 --scenario nextcloud --out "$TMP_DIR/x" >/dev/null 2>&1; then
        fail "an unknown scenario must be rejected"
    fi
    if "$E2E_DIR/scripts/run-linux-e2e.sh" --dry-run --arch riscv64 --scenario immich --out "$TMP_DIR/x" >/dev/null 2>&1; then
        fail "an unknown architecture must be rejected"
    fi
}

# --- compose and workflow -----------------------------------------------------

check_compose() {
    local file=$E2E_DIR/compose/compose.yaml image
    [ -f "$file" ] || { fail "missing compose/compose.yaml"; return; }
    while IFS= read -r image; do
        [[ $image =~ ^\$\{[A-Z0-9_]+_IMAGE(:\?[^}]*)?\}$ ]] || fail "compose.yaml: image must come from images.lock: $image"
    done < <(sed -nE 's/^[[:space:]]+image:[[:space:]]*"?([^"]+)"?$/\1/p' "$file")
    for text in /var/backups/muckisafe MUWA_E2E_APP_IMAGE CADDY_IMAGE internal-certs; do
        grep -qF "$text" "$file" || fail "compose.yaml misses $text"
    done
    [ -f "$E2E_DIR/compose/Dockerfile.agent-plugin" ] || fail "missing compose/Dockerfile.agent-plugin"
}

main() {
    need jq
    need comm
    check_images_lock
    check_versions_env
    check_scripts
    for service in "${SERVICES[@]}"; do
        check_scenario "$service"
    done
    check_bitwarden_image_platform
    check_compose
    check_dry_run
    if [ "$FAILURES" -gt 0 ]; then
        echo "E2E harness selftest: $FAILURES failure(s)" >&2
        exit 1
    fi
    echo "E2E harness selftest passed"
}

main "$@"
