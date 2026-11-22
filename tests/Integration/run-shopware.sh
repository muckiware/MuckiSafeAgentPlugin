#!/usr/bin/env bash
#
# MuckiSafeAgentPlugin — Shopware integration test harness.
#
# Provisions an isolated, installed Shopware (shopware/production checkout at
# a supported tag) against MySQL 8.0/8.4, adds MuckiFacilityPlugin (pinned
# tag) and this plugin checkout under custom/plugins/ (both become composer
# path packages through shopware/production's repositories entry), runs
# composer install, system:install, plugin:refresh, plugin:install
# --activate for both plugins and finally PHPUnit from the Shopware root
# with this plugin's phpunit configuration.
#
# Contract — keep these values identical between this script,
# tests/bootstrap.php and the CI workflow (.github/workflows/php-tests.yml):
#   TEST_DATABASE_URL    mysql://user:***@host:port/database
#                       (set from outside: pre-provisioned DB / CI service)
#   MUWA_TEST_WORKSPACE working directory for the checkouts
#                       (default /tmp/muwa-integration)
#   MUWA_TEST_FACILITY_REF  MuckiFacilityPlugin tag (default v0.11.0)
#   MUWA_TEST_PLUGIN_VERSION  version given to the harness copy of this
#                            plugin (default 0.1.0; the source composer.json
#                            intentionally ships no version field)
#
# Usage (matrix values from the implementation plan):
#   tests/Integration/run-shopware.sh --shopware v6.6.10.25 --mysql 8.0
#   tests/Integration/run-shopware.sh --shopware v6.7.14.1 --mysql 8.4
#
# Execution environments:
#   * native:  php + composer on PATH (CI runner) → inner phase runs locally
#   * ddev:    php + composer only inside the running DDEV web container
#              (Torsten's Mac) → outer phase starts MySQL via docker on the
#              host, inner phase runs via `ddev exec` and reaches the
#              database through host.docker.internal.
#
set -euo pipefail

readonly DEFAULT_SHOPWARE_TAG="v6.7.14.1"
readonly DEFAULT_MYSQL_VERSION="8.4"
readonly DEFAULT_WORKSPACE="/tmp/muwa-integration"
readonly DEFAULT_FACILITY_REF="v0.11.0"
readonly FACILITY_CONSTRAINT="^0.11"
readonly MYSQL_PORT_80="3380"
readonly MYSQL_PORT_84="3384"
readonly MYSQL_ROOT_PASSWORD="***"

log() { printf '\033[1;36m[muwa-harness]\033[0m %s\n' "$*"; }
die() { printf '\033[1;31m[muwa-harness] ERROR:\033[0m %s\n' "$*" >&2; exit 1; }

SHOPWARE_TAG="${MUWA_TEST_SHOPWARE:-$DEFAULT_SHOPWARE_TAG}"
MYSQL_VERSION="${MUWA_TEST_MYSQL:-$DEFAULT_MYSQL_VERSION}"
WORKSPACE="${MUWA_TEST_WORKSPACE:-$DEFAULT_WORKSPACE}"
FACILITY_REF="${MUWA_TEST_FACILITY_REF:-$DEFAULT_FACILITY_REF}"
INNER=0
FRESH=0

while [ $# -gt 0 ]; do
    case "$1" in
        --shopware) SHOPWARE_TAG="$2"; shift 2 ;;
        --mysql) MYSQL_VERSION="$2"; shift 2 ;;
        --inner) INNER=1; shift ;;
        --fresh) FRESH=1; shift ;;
        -h|--help) sed -n '2,30p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) die "unknown argument: $1 (see --help)" ;;
    esac
done

# ---------------------------------------------------------------------------
# inner phase — expects php, composer, git on PATH and TEST_DATABASE_URL set
# ---------------------------------------------------------------------------
run_inner() {
    [ -n "${TEST_DATABASE_URL:-}" ] || die "--inner requires TEST_DATABASE_URL"

    command -v php >/dev/null 2>&1 || die "php is not available in the inner phase"
    command -v composer >/dev/null 2>&1 || die "composer is not available in the inner phase"
    command -v git >/dev/null 2>&1 || die "git is not available in the inner phase"

    local plugin_src shop_dir facility_dir agent_dir
    plugin_src="$(cd "$(dirname "$0")/../.." && pwd)"
    grep -q 'muckiware/mucki-safe-agent-plugin' "$plugin_src/composer.json" 2>/dev/null \
        || die "plugin composer.json not found at $plugin_src — wrong checkout?"

    shop_dir="$WORKSPACE/shop-$SHOPWARE_TAG"
    facility_dir="$shop_dir/custom/plugins/MuckiFacilityPlugin"
    agent_dir="$shop_dir/custom/plugins/MuckiSafeAgentPlugin"

    if [ "$FRESH" = "1" ]; then
        rm -rf "$shop_dir"
    fi

    log "workspace: $shop_dir"
    log "shopware:  $SHOPWARE_TAG, mysql: $MYSQL_VERSION, facility: $FACILITY_REF"

    # 1) shopware/production checkout (cached across runs)
    if [ ! -f "$shop_dir/composer.json" ]; then
        rm -rf "$shop_dir"
        log "cloning shopware/production@$SHOPWARE_TAG"
        git clone --depth 1 --branch "$SHOPWARE_TAG" https://github.com/shopware/production.git "$shop_dir"
    fi
    mkdir -p "$shop_dir/custom/plugins"

    # 2) MuckiFacilityPlugin checkout — custom/plugins/* is a composer path
    #    repository in shopware/production, so the composer graph resolves
    #    the facility from this checkout and its dependencies from Packagist.
    if [ ! -d "$facility_dir" ]; then
        log "cloning muckiware/MuckiFacilityPlugin@$FACILITY_REF"
        git clone --depth 1 --branch "$FACILITY_REF" https://github.com/muckiware/MuckiFacilityPlugin.git "$facility_dir"
    elif ! grep -q "\"version\": \"$FACILITY_REF\"" "$facility_dir/composer.json" 2>/dev/null; then
        die "existing MuckiFacilityPlugin checkout in $facility_dir does not match $FACILITY_REF — run with --fresh"
    fi

    # 3) Fresh copy of this plugin checkout (never stale, no git metadata)
    rm -rf "$agent_dir"
    mkdir -p "$agent_dir"
    (
        cd "$plugin_src"
        tar cf - \
            --exclude='*.git' \
            --exclude='./.github' \
            --exclude='*.phpunit.result.cache' \
            --exclude='*.DS_Store' \
            --exclude='./var' \
            --exclude='./vendor' \
            .
    ) | tar xf - -C "$agent_dir"
    # The source composer.json carries no version (Packagist convention), so
    # composer would otherwise derive one from the surrounding workspace VCS.
    # Pin the version of the harness copy instead.
    composer config --working-dir="$agent_dir" version "${MUWA_TEST_PLUGIN_VERSION:-0.1.0}" >/dev/null

    # 4) shopware root dependencies
    cd "$shop_dir"
    if [ "$FRESH" = "1" ] || [ ! -d vendor/shopware ] || [ ! -f composer.lock ]; then
        log "composer install (shopware root)"
        composer install --no-dev --no-interaction --no-progress
    fi

    # 5) plugins + dev tooling through the composer graph
    log "composer require muckiware/facility-plugin + muckiware/mucki-safe-agent-plugin"
    composer require \
        "muckiware/facility-plugin:$FACILITY_CONSTRAINT" \
        "muckiware/mucki-safe-agent-plugin:*" \
        --no-interaction --no-scripts --no-progress

    log "composer require --dev phpunit/phpstan/browser-kit"
    composer require --dev phpunit/phpunit:^11.5 phpstan/phpstan symfony/browser-kit \
        --no-interaction --no-scripts --no-progress

    # 6) Plan contract: composer refuses to remove the facility package while
    #    the agent plugin is installed.
    log "verifying composer graph (agent requires facility)"
    composer why muckiware/facility-plugin 2>/dev/null \
        | grep -q 'muckiware/mucki-safe-agent-plugin' \
        || die "composer graph does not connect muckiware/mucki-safe-agent-plugin -> muckiware/facility-plugin"

    # 7) fresh database (drop + create) and .env
    log "recreating database from TEST_DATABASE_URL"
    php -r '
        $url = parse_url(getenv("TEST_DATABASE_URL"));
        if (!is_array($url) || ($url["scheme"] ?? "") !== "mysql") {
            fwrite(STDERR, "TEST_DATABASE_URL must be a mysql:// URL\n");
            exit(1);
        }
        $pdo = new PDO(
            sprintf("mysql:host=%s;port=%d", $url["host"], $url["port"] ?? 3306),
            $url["user"] ?? null,
            $url["pass"] ?? null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $database = ltrim($url["path"] ?? "", "/");
        $safe = str_replace("`", "``", $database);
        $pdo->exec("DROP DATABASE IF EXISTS `" . $safe . "`");
        $pdo->exec("CREATE DATABASE `" . $safe . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        echo "database " . $database . " recreated\n";
    '

    printf 'APP_ENV=dev\nAPP_URL=http://localhost\nAPP_SECRET=%s\nDATABASE_URL=%s\n' \
        "$(php -r 'echo bin2hex(random_bytes(16));')" \
        "$TEST_DATABASE_URL" > .env

    # 8) install shopware + both plugins
    log "system:install --basic-setup --force"
    bin/console system:install --basic-setup --force

    log "plugin:refresh"
    bin/console plugin:refresh

    log "plugin:install --activate MuckiFacilityPlugin"
    bin/console plugin:install --activate MuckiFacilityPlugin

    log "plugin:install --activate MuckiSafeAgentPlugin"
    bin/console plugin:install --activate MuckiSafeAgentPlugin

    # 9) PHPUnit from the shopware root with the plugin configuration
    log "phpunit (unit + integration) from the shopware root"
    export TEST_DATABASE_URL
    vendor/bin/phpunit --configuration="$agent_dir/phpunit.xml.dist" --no-progress

    log "GREEN: shopware $SHOPWARE_TAG / mysql $MYSQL_VERSION"
}

# ---------------------------------------------------------------------------
# outer phase — provision MySQL, then run the inner phase where php lives
# ---------------------------------------------------------------------------
# Trap state lives at script level: the EXIT trap fires after run_outer
# returned, where function-local variables no longer exist.
MYSQL_STARTED=0
MYSQL_CONTAINER=""

cleanup_mysql_container() {
    if [ "${MYSQL_STARTED:-0}" = "1" ] && [ "${MUWA_TEST_KEEP_MYSQL:-0}" != "1" ]; then
        log "stopping mysql container $MYSQL_CONTAINER"
        docker stop "$MYSQL_CONTAINER" >/dev/null 2>&1 || true
    fi
}

run_outer() {
    local mysql_port db_name

    case "$MYSQL_VERSION" in
        8.0) mysql_port="$MYSQL_PORT_80" ;;
        8.4) mysql_port="$MYSQL_PORT_84" ;;
        *) die "unsupported --mysql version: $MYSQL_VERSION (supported: 8.0, 8.4)" ;;
    esac

    db_name="muwa_$(printf '%s_%s' "$MYSQL_VERSION" "$SHOPWARE_TAG" | tr -c 'a-zA-Z0-9' '_' | tr -s '_' | sed 's/^_*//;s/_*$//')"
    MYSQL_CONTAINER="muwa-int-mysql-$MYSQL_VERSION"

    if [ -z "${TEST_DATABASE_URL:-}" ]; then
        command -v docker >/dev/null 2>&1 \
            || die "docker is required to start MySQL $MYSQL_VERSION (or provide TEST_DATABASE_URL)"

        log "starting mysql:$MYSQL_VERSION container on 127.0.0.1:$mysql_port"
        docker rm -f "$MYSQL_CONTAINER" >/dev/null 2>&1 || true
        docker run -d --name "$MYSQL_CONTAINER" \
            -p "127.0.0.1:$mysql_port:3306" \
            -e "MYSQL_ROOT_PASSWORD=$MYSQL_ROOT_PASSWORD" \
            "mysql:$MYSQL_VERSION" >/dev/null

        log "waiting for mysql:$MYSQL_VERSION to accept connections"
        local tries=0
        until docker exec "$MYSQL_CONTAINER" mysqladmin ping -uroot "-p$MYSQL_ROOT_PASSWORD" --silent >/dev/null 2>&1; do
            tries=$((tries + 1))
            if [ "$tries" -gt 90 ]; then
                docker rm -f "$MYSQL_CONTAINER" >/dev/null 2>&1 || true
                die "mysql:$MYSQL_VERSION did not become ready within 180s"
            fi
            sleep 2
        done
        MYSQL_STARTED=1
    fi

    trap cleanup_mysql_container EXIT

    local extra_flags=""
    [ "$FRESH" = "1" ] && extra_flags="--fresh"

    if command -v php >/dev/null 2>&1 && command -v composer >/dev/null 2>&1; then
        # native mode (CI runner): the DB runs on 127.0.0.1.
        if [ -z "${TEST_DATABASE_URL:-}" ]; then
            export TEST_DATABASE_URL="mysql://root:***@127.0.0.1:${mysql_port}/${db_name}"
        fi
        log "execution mode: native"
        "$0" --inner --shopware "$SHOPWARE_TAG" --mysql "$MYSQL_VERSION" $extra_flags
    elif command -v ddev >/dev/null 2>&1 && ddev exec test -f /var/www/html/composer.json >/dev/null 2>&1; then
        # ddev mode (Torsten's Mac): php lives inside the DDEV web container.
        local ddev_plugin_dir="${MUWA_TEST_DDEV_PLUGIN_DIR:-/var/www/html/custom/static-plugins/MuckiSafeAgentPlugin}"

        ddev exec test -f "$ddev_plugin_dir/composer.json" >/dev/null 2>&1 \
            || die "plugin checkout is not visible in the ddev container at $ddev_plugin_dir"

        ddev mutagen sync >/dev/null 2>&1 || true
        ddev exec grep -q 'muckiware/mucki-safe-agent-plugin' "$ddev_plugin_dir/composer.json" >/dev/null 2>&1 \
            || die "the ddev container sees stale plugin files — run ddev mutagen sync"

        export TEST_DATABASE_URL="mysql://root:***@host.docker.internal:${mysql_port}/${db_name}"
        log "execution mode: ddev ($ddev_plugin_dir) — database host.docker.internal:$mysql_port"

        ddev exec sh -c "cd '$ddev_plugin_dir' \
            && TEST_DATABASE_URL='$TEST_DATABASE_URL' \
               WORKSPACE='$WORKSPACE' \
               MUWA_TEST_FACILITY_REF='$FACILITY_REF' \
               ./tests/Integration/run-shopware.sh --inner --shopware '$SHOPWARE_TAG' --mysql '$MYSQL_VERSION' $extra_flags"
    else
        die "need php+composer on PATH (native/CI) or a running ddev project (Mac); alternatively provide TEST_DATABASE_URL"
    fi
}

if [ "$INNER" = "1" ]; then
    run_inner
else
    run_outer
fi
