param(
    [string]$Server = 'localhost\SQL2025',
    [string]$Database = 'e_kabineti',
    [Parameter(Mandatory=$true)][string]$SqlBackupDirectory
)

$ErrorActionPreference = 'Stop'
$resolved = [IO.Path]::GetFullPath($SqlBackupDirectory)
if (-not (Test-Path -LiteralPath $resolved)) { New-Item -ItemType Directory -Path $resolved | Out-Null }
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$file = Join-Path $resolved "$Database-full-$stamp.bak"
$escaped = $file.Replace("'", "''")
sqlcmd -S $Server -E -C -b -Q "BACKUP DATABASE [$Database] TO DISK=N'$escaped' WITH COPY_ONLY, CHECKSUM, COMPRESSION, INIT; RESTORE VERIFYONLY FROM DISK=N'$escaped' WITH CHECKSUM;"
if ($LASTEXITCODE -ne 0) { throw 'Резервната копија или VERIFYONLY не успеа.' }
Write-Host "Backup i verifikuar: $file" -ForegroundColor Green
