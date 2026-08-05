# scripts/backup_database.ps1
# Dumps the inventorymanagement database to a timestamped .sql file. Kept
# forever - nothing in this script ever deletes an old backup. Meant to be
# run on a schedule (see Windows Task Scheduler setup instructions from
# Claude) rather than by hand - that's what makes this "continuous" instead
# of "whenever someone remembers to."

$MysqlDump = "C:\xampp\mysql\bin\mysqldump.exe"
$DbName    = "inventorymanagement"
$DbHost    = "127.0.0.1"
$DbUser    = "root"
$BackupDir = "$PSScriptRoot\..\backups"

if (-not (Test-Path $BackupDir)) {
    New-Item -ItemType Directory -Path $BackupDir | Out-Null
}

$timestamp  = Get-Date -Format "yyyy-MM-dd_HH-mm"
$backupFile = Join-Path $BackupDir "backup_$timestamp.sql"

# PowerShell's own ">" redirect (Out-File) defaults to UTF-16 encoding,
# which would silently corrupt mysqldump's output into an unrestorable
# file. Routing through cmd.exe instead gives a raw byte-for-byte redirect,
# same as running mysqldump directly at a command prompt.
cmd.exe /c "`"$MysqlDump`" -h $DbHost -u $DbUser $DbName > `"$backupFile`""

if (-not (Test-Path $backupFile) -or (Get-Item $backupFile).Length -eq 0) {
    Write-Error "Backup produced an empty or missing file - something went wrong. Check that MySQL is running and the mysqldump path above is correct."
    if (Test-Path $backupFile) { Remove-Item $backupFile }
    exit 1
}

Write-Output "Backup saved: $backupFile"
