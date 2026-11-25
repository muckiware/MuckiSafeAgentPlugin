# shellcheck shell=bash
# Setup steps of the Linux service E2E: MuckiSafe stack (real compose file
# plus tests/E2E/compose/compose.yaml), HTTPS proxy with a trusted local CA,
# the packaged agent under systemd, Facility repository, actions and hooks.
# Sourced by run-linux-e2e.sh after lib.sh and the scenario.

# Modes (run-linux-e2e.sh options):
#   MUCKISAFE_MODE=source   image = MUCKISAFE_BASE_IMAGE + this plugin from source
#   MUCKISAFE_MODE=release  image = MUWA_E2E_MUCKISAFE_IMAGE (released tag@sha256)
#   AGENT_MODE=source       agent built from agent/ with Go
#   AGENT_MODE=release      agent from the release tarball MUWA_E2E_AGENT_TARBALL
MUCKISAFE_MODE=${MUCKISAFE_MODE:-source}
AGENT_MODE=${AGENT_MODE:-source}
REPOSITORY_ID=""

# The provisioned host id (persisted, because every check is a subshell).
agent_host_id() {
    cat "$WORK_DIR/agent-host-id"
}

# --- stack --------------------------------------------------------------------

random_hex() {
    od -An -N"$1" -tx1 /dev/urandom | tr -d ' \n'
}

stack_image() {
    case $MUCKISAFE_MODE in
        source) echo "muwa-e2e/muckisafe:source" ;;
        release)
            [[ ${MUWA_E2E_MUCKISAFE_IMAGE:-} =~ ^[a-z0-9./-]+:v[0-9]+\.[0-9]+\.[0-9]+@sha256:[0-9a-f]{64}$ ]] ||
                die "release mode needs MUWA_E2E_MUCKISAFE_IMAGE=ghcr.io/muckiware/muckisafe:vX.Y.Z@sha256:..."
            echo "$MUWA_E2E_MUCKISAFE_IMAGE"
            ;;
        *) die "unknown MuckiSafe mode $MUCKISAFE_MODE" ;;
    esac
}

# The source image adds this plugin exactly as the Composer distribution
# would contain it (git archive honours export-ignore), so agent/, tests/ and
# CI files never reach the image.
build_source_image() {
    local context=$WORK_DIR/plugin-src ref
    rm -rf "$context"
    mkdir -p "$context/plugin"
    ref=$(git -C "$REPO_ROOT" stash create 2>/dev/null || true)
    git -C "$REPO_ROOT" archive --format=tar "${ref:-HEAD}" | tar -x -C "$context/plugin"
    cp "$E2E_DIR/compose/Dockerfile.agent-plugin" "$context/Dockerfile"
    docker build --pull --build-arg "BASE_IMAGE=$MUCKISAFE_BASE_IMAGE" -t "$(stack_image)" "$context"
}

checkout_muckisafe() {
    [ -f "$MUCKISAFE_DIR/docker-compose.yml" ] && return 0
    git clone --quiet https://github.com/muckiware/MuckiSafe.git "$MUCKISAFE_DIR"
    git -C "$MUCKISAFE_DIR" checkout --quiet "$MUCKISAFE_COMPOSE_REF"
}

# write_stack_env: fresh secrets for every run, nothing from a real .env.
write_stack_env() {
    local keyring
    keyring=$(printf '{"active":1,"keys":{"1":"%s"}}' "$(random_hex 32)")
    umask 077
    cat >"$WORK_DIR/stack.env" <<EOF
MUWA_E2E_APP_IMAGE=$(stack_image)
CADDY_IMAGE=$CADDY_IMAGE
HTTP_PORT=$HTTP_PORT
HTTPS_PORT=$HTTPS_PORT
HTTPS_HOST=$HTTPS_HOST
APP_URL=http://127.0.0.1:$HTTP_PORT
APP_SECRET=$(random_hex 32)
INSTANCE_ID=$(random_hex 16)
DB_PASSWORD=$(random_hex 16)
DB_ROOT_PASSWORD=$(random_hex 16)
MUWA_FACILITY_SECRET=$(random_hex 32)
ADMIN_USERNAME=admin
ADMIN_PASSWORD=$(random_hex 12)
MUWA_AGENT_ENCRYPTION_KEYRING='$keyring'
MUCKI_AGENT_WAKEUP_PATH=$WAKEUP_HOST_DIR
BACKUP_REPOS_HOST_DIR=$BACKUP_REPOS_HOST_DIR
SYMFONY_TRUSTED_PROXIES=private_ranges
EOF
    umask 022
}

# write_sources_override: the scenario's host paths, read-only at the same
# path in app and scheduler, so restic sees exactly the host tree. Docker must
# never create a missing source (it would create it empty and root-owned and
# hide the scenario's own setup): scenario_prepare_host creates them first.
write_sources_override() {
    local mounts="" path
    while IFS= read -r path; do
        [ -n "$path" ] || continue
        [ -d "$path" ] || die "source mount $path does not exist; scenario_prepare_host must create it"
        mounts="$mounts      - type: bind
        source: \"$path\"
        target: \"$path\"
        read_only: true
        bind:
          create_host_path: false
"
    done < <(scenario_source_mounts)
    {
        echo "services:"
        for service in app scheduler; do
            echo "  $service:"
            if [ -n "$mounts" ]; then
                echo "    volumes:"
                printf '%s' "$mounts"
            else
                echo "    volumes: []"
            fi
        done
    } >"$WORK_DIR/sources.override.yml"
}

stack_prepare() {
    install -d -m 0755 "$WORK_DIR"
    touch "$WORK_DIR/.muwa-e2e"
    install -d -o 33 -g 33 -m 0770 "$BACKUP_REPOS_HOST_DIR"
    # Same step as README/install.sh: before the first start, root:33 0770.
    install -d -o root -g 33 -m 0770 "$WAKEUP_HOST_DIR"
    checkout_muckisafe
    if [ "$MUCKISAFE_MODE" = source ]; then
        build_source_image
    fi
    write_stack_env
    # Host paths the stack mounts must exist before docker compose up.
    scenario_prepare_host
    write_sources_override
}

app_healthy() {
    [ "$(docker inspect -f '{{.State.Health.Status}}' "$(stack ps -q app)" 2>/dev/null)" = healthy ]
}

# trust_proxy_ca: the agent verifies HTTPS against the system CA store
# (there is no TLS skip), so the proxy's local root CA becomes trusted.
trust_proxy_ca() {
    wait_for 60 "caddy root CA" stack exec -T caddy test -f /data/caddy/pki/authorities/local/root.crt
    stack exec -T caddy cat /data/caddy/pki/authorities/local/root.crt >/usr/local/share/ca-certificates/muwa-e2e.crt
    update-ca-certificates >/dev/null
    grep -q " $HTTPS_HOST\$" /etc/hosts || echo "127.0.0.1 $HTTPS_HOST" >>/etc/hosts
    wait_for 60 "HTTPS proxy" curl -fsS -o /dev/null "https://$HTTPS_HOST:$HTTPS_PORT/admin"
}

stack_up() {
    [ "$MUCKISAFE_MODE" = release ] && stack pull --quiet
    stack up -d --wait --wait-timeout 600 db
    stack up -d
    wait_for 600 "MuckiSafe app healthy" app_healthy
    trust_proxy_ca
    api_login
    console plugin:list | grep -E 'MuckiSafeAgentPlugin .* (Yes|yes) .* (Yes|yes)' >/dev/null ||
        die "MuckiSafeAgentPlugin is not installed and active in the stack"
    # A fresh Facility is disabled: muckiware:backup:create then skips the
    # backup silently with exit 0.
    console system:config:set MuckiFacilityPlugin.config.active true --json
    console system:config:get MuckiFacilityPlugin.config.active | grep -q '=> true' ||
        die "MuckiFacilityPlugin could not be enabled"
}

# --- agent --------------------------------------------------------------------

AGENT_PACKAGE_DIR=""
AGENT_BINARY=""

agent_package() {
    case $AGENT_MODE in
        source)
            AGENT_PACKAGE_DIR=$REPO_ROOT/agent/packaging
            AGENT_BINARY=$WORK_DIR/agent/mucki-agent
            mkdir -p "$WORK_DIR/agent"
            (cd "$REPO_ROOT/agent" && CGO_ENABLED=0 go build -trimpath -o "$AGENT_BINARY" ./cmd/mucki-agent)
            ;;
        release)
            local tarball=${MUWA_E2E_AGENT_TARBALL:-}
            [[ -f $tarball && -f $tarball.sha256 ]] || die "release mode needs MUWA_E2E_AGENT_TARBALL with its .sha256"
            (cd "$(dirname -- "$tarball")" && sha256sum --check --strict "$(basename -- "$tarball").sha256")
            rm -rf "$WORK_DIR/agent"
            mkdir -p "$WORK_DIR/agent"
            tar -xzf "$tarball" -C "$WORK_DIR/agent" --strip-components=1
            AGENT_PACKAGE_DIR=$WORK_DIR/agent
            AGENT_BINARY=$WORK_DIR/agent/mucki-agent-linux-$E2E_ARCH
            ;;
        *) die "unknown agent mode $AGENT_MODE" ;;
    esac
    "$AGENT_BINARY" --version
    # For scenarios that rerun install.sh (policy paths changed).
    echo "$AGENT_PACKAGE_DIR" >"$WORK_DIR/agent-package-dir"
    echo "$AGENT_BINARY" >"$WORK_DIR/agent-binary"
}

# agent_write_config TRANSPORT: https (default) or http (explicit opt-in).
agent_write_config() {
    local url insecure=false key_id
    key_id=$(cat "$WORK_DIR/agent-key-id")
    case $1 in
        https) url="https://$HTTPS_HOST:$HTTPS_PORT" ;;
        http) url="http://127.0.0.1:$HTTP_PORT"; insecure=true ;;
        *) die "unknown transport $1" ;;
    esac
    umask 077
    cat >"$AGENT_CONFIG.new" <<EOF
server:
  url: $url
  allowInsecureHttp: $insecure
  keyId: $key_id
  secretFile: $AGENT_SECRET
  requestTimeout: 30s
agent:
  pollInterval: 10s
  heartbeatInterval: 10s
  leaseDuration: 30s
  maxConcurrency: 1
  wakeupDirectory: $WAKEUP_HOST_DIR
  stateDirectory: $AGENT_STATE
  policyFile: $AGENT_POLICY
EOF
    umask 022
    chown root:root "$AGENT_CONFIG.new"
    chmod 0600 "$AGENT_CONFIG.new"
    mv -f "$AGENT_CONFIG.new" "$AGENT_CONFIG"
}

agent_provision() {
    local response
    response=$(api POST /api/_action/muwa/agent/provision "$(jq -nc --arg n "e2e-$SCENARIO_NAME-$E2E_ARCH" '{name: $n}')")
    jq -r '.id' <<<"$response" >"$WORK_DIR/agent-host-id"
    jq -r '.keyId' <<<"$response" >"$WORK_DIR/agent-key-id"
    (umask 077; jq -r '.secret' <<<"$response" >"$AGENT_SECRET")
    chown root:root "$AGENT_SECRET"
    chmod 0600 "$AGENT_SECRET"
}

# agent_mark_restart: remembers the control plane's UTC time; agent_active
# then needs a heartbeat after it (the agent really runs again).
agent_mark_restart() {
    sql "SELECT UTC_TIMESTAMP(3)" >"$WORK_DIR/agent-seen-mark"
}

# agent_active: enabled, not revoked and seen since the last mark
# (muwa_agent_host has no status column: pending = never seen).
agent_active() {
    local mark
    mark=$(cat "$WORK_DIR/agent-seen-mark" 2>/dev/null || echo '1970-01-01 00:00:00')
    [ "$(sql "SELECT COUNT(*) FROM muwa_agent_host WHERE id = UNHEX('$(agent_host_id)')
        AND enabled = 1 AND revoked_at IS NULL AND last_seen_at >= '$mark'")" = 1 ]
}

# agent_install: first install.sh run (no config yet: installs, never
# starts), then provisioning, config, secret and policy, second run starts
# the hardened unit — the documented operator flow.
agent_install() {
    # Never replace a real agent installation of this host.
    if [ -e /etc/mucki-agent ] && [ ! -e /etc/mucki-agent/.muwa-e2e ]; then
        die "/etc/mucki-agent exists and is no E2E installation; refusing to touch it"
    fi
    agent_package
    "$AGENT_PACKAGE_DIR/install.sh" --binary "$AGENT_BINARY" --no-start
    touch /etc/mucki-agent/.muwa-e2e
    agent_provision
    agent_write_config https
    install -o root -g root -m 0644 "$(scenario_policy)" "$AGENT_POLICY"
    "/usr/local/bin/mucki-agent" config validate --config "$AGENT_CONFIG"
    agent_mark_restart
    "$AGENT_PACKAGE_DIR/install.sh" --binary "$AGENT_BINARY" --container "$(stack ps -q app)" --container "$(stack ps -q scheduler)"
    wait_for 60 "agent host active" agent_active
}

# --- Facility repository, actions and hooks -------------------------------------

backup_paths_json() {
    local position=0 path json="[]"
    while IFS= read -r path; do
        [ -n "$path" ] || continue
        json=$(jq -c --arg id "$(uuid)" --arg path "$path" --argjson pos "$position" \
            '. + [{id: $id, backupPath: $path, compress: false, position: $pos, isDefault: false}]' <<<"$json")
        position=$((position + 1))
    done < <(scenario_backup_paths)
    echo "$json"
}

backup_repository_create() {
    local password payload
    REPOSITORY_ID=$(uuid)
    password=$(random_hex 16)
    payload=$(jq -nc --arg id "$REPOSITORY_ID" --arg name "e2e-$SCENARIO_NAME" \
        --arg path "$CONTAINER_REPOS_DIR/$SCENARIO_NAME-$REPOSITORY_ID" --arg pw "$password" \
        --argjson paths "$(backup_paths_json)" \
        '{id: $id, active: true, name: $name, type: "noneDatabase", hostname: "e2e", repositoryPath: $path,
          repositoryPassword: $pw, repositoryRepeatPassword: $pw, backupPaths: $paths,
          preBackupCommandType: "none", postBackupCommandType: "none"}')
    api POST /api/_action/muwa/backup/repository/init "$payload" >/dev/null
    echo "$REPOSITORY_ID" >"$WORK_DIR/repository-id"
    # For scenarios that inspect the snapshot content (restic ls/dump).
    (umask 077; printf '%s' "$password" >"$WORK_DIR/repository-password")
}

# restic_in_app ARGS...: restic against the scenario repository inside the
# app container (as www-data, password from WORK_DIR).
restic_in_app() {
    local repository_id
    repository_id=$(cat "$WORK_DIR/repository-id")
    stack exec -T -u www-data -e RESTIC_CACHE_DIR=/tmp/restic-cache -e RESTIC_PASSWORD="$(cat "$WORK_DIR/repository-password")" app \
        restic -r "$CONTAINER_REPOS_DIR/$SCENARIO_NAME-$repository_id" "$@"
}

# hooks_create: one action and one hook per line of scenario_hooks
# ("PHASE ACTION_FILE IDEMPOTENCY_CLASS TIMEOUT_SECONDS FAIL_ON_ERROR").
hooks_create() {
    local phase file class timeout fail_on_error action_id payload
    while read -r phase file class timeout fail_on_error; do
        [ -n "$phase" ] || continue
        payload=$(jq -nc --arg name "e2e-$SCENARIO_NAME-$phase" --rawfile definition "$REPO_ROOT/$file" \
            --arg class "$class" --argjson timeout "$timeout" \
            '{technicalName: $name, label: $name, enabled: true, definition: $definition,
              defaultTimeoutSeconds: $timeout, maxTimeoutSeconds: $timeout, idempotencyClass: $class}')
        action_id=$(api POST /api/_action/muwa/agent/action "$payload" | jq -r '.id')
        payload=$(jq -nc --arg repo "$(cat "$WORK_DIR/repository-id")" --arg phase "$phase" --arg host "$(agent_host_id)" \
            --arg action "$action_id" --argjson timeout "$timeout" --argjson fail "$fail_on_error" \
            '{facilityRepositoryId: $repo, phase: $phase, agentHostId: $host, agentActionId: $action,
              timeoutSeconds: $timeout, failOnError: $fail, enabled: true}')
        api POST /api/_action/muwa/agent/repository-hook "$payload" >/dev/null
    done < <(scenario_hooks)
}
