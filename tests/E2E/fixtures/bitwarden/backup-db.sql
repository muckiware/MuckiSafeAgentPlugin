-- /backup-db.sql of the Bitwarden MSSQL container, E2E replica (Task 32).
-- Full backup of the vault database to
-- /etc/bitwarden/mssql/backups/vault_FULL_<yyyymmdd_hhmmss>.BAK; the
-- timestamp comes from backup-db.sh as the sqlcmd variable "now".
DECLARE @DatabaseName varchar(100) = 'vault';
DECLARE @BackupDir varchar(100) = '/etc/bitwarden/mssql/backups/';
DECLARE @BackupFile varchar(200) = @BackupDir + @DatabaseName + '_FULL_' + '$(now)' + '.BAK';
DECLARE @BackupName varchar(200) = @DatabaseName + ' full backup for _' + '$(now)';

BACKUP DATABASE @DatabaseName TO DISK = @BackupFile WITH INIT, NAME = @BackupName, NOSKIP, NOFORMAT;
