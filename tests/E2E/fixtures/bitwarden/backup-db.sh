#!/bin/bash
# /backup-db.sh of the Bitwarden MSSQL container, E2E replica (Task 32).
#
# The official bitwarden/mssql image (bitwarden/server, util/MsSql) adds
# /backup-db.sh and /backup-db.sql to mcr.microsoft.com/mssql/server. The
# E2E runs the pinned Microsoft image and mounts this replica read-only at
# the same path (fixtures/bitwarden/compose.yaml). Like the original it
# creates a full backup vault_FULL_<yyyymmdd_hhmmss>.BAK of the vault
# database in /etc/bitwarden/mssql/backups (bwdata/mssql/backups on the
# host) and then deletes .BAK files older than 32 days. Difference: success
# is proven by `sqlcmd -b` and the non-empty .BAK instead of grepping the
# errorlog, so a failed backup always ends with exit 1.
#
# Fault injection (read-only control directory /run/muwa-e2e, written by
# scenarios/bitwarden.sh on the host):
#   stall     while it exists, wait before the first effect (at most 600 s)
#   artifact  "none": exit 0 without a backup (only the stale BAK remains)
#             "bytes:N": truncate the new BAK to N bytes after the backup
# A failing backup command is injected for real (vault database offline).
set -u

control=/run/muwa-e2e
backup_dir=/etc/bitwarden/mssql/backups

waited=0
while [ -e "$control/stall" ] && [ "$waited" -lt 600 ]; do
    sleep 1
    waited=$((waited + 1))
done

artifact=$(cat "$control/artifact" 2>/dev/null || true)
if [ "$artifact" = none ]; then
    echo "backup-db: E2E control: no backup created"
    exit 0
fi

run_sqlcmd() {
    if [ -x /opt/mssql-tools18/bin/sqlcmd ]; then
        /opt/mssql-tools18/bin/sqlcmd -C "$@"
    else
        /opt/mssql-tools/bin/sqlcmd "$@"
    fi
}

# Backup timestamp
now=$(date +%Y%m%d_%H%M%S)
export now
file=$backup_dir/vault_FULL_$now.BAK

# Do a new backup (password: SQLCMDPASSWORD of the container environment)
if ! run_sqlcmd -S localhost -U sa -b -v now="$now" -i /backup-db.sql; then
    echo "backup-db: BACKUP DATABASE vault failed" >&2
    exit 1
fi
if [ ! -f "$file" ] || [ ! -s "$file" ]; then
    echo "backup-db: $file was not created" >&2
    exit 1
fi

case $artifact in
    bytes:*) truncate -s "${artifact#bytes:}" "$file" ;;
esac

# Delete backup files older than 32 days
find "$backup_dir" -mindepth 1 -maxdepth 1 -type f -name '*.BAK' -mtime +32 -delete
