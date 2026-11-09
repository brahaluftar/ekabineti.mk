param(
    [string]$ServerInstance = 'localhost\SQL2025',
    [string]$Database = 'e_kabineti_test',
    [string]$Output = 'php81\database\schema.sql'
)

$ErrorActionPreference = 'Stop'
foreach ($assembly in @('Microsoft.SqlServer.ConnectionInfo','Microsoft.SqlServer.Smo','Microsoft.SqlServer.SmoExtended')) {
    if ($null -eq [Reflection.Assembly]::LoadWithPartialName($assembly)) { throw "SMO assembly not found: $assembly" }
}

$server = [Microsoft.SqlServer.Management.Smo.Server]::new($ServerInstance)
$databaseObject = $server.Databases[$Database]
if ($null -eq $databaseObject) { throw "Database not found: $Database" }

$transfer = [Microsoft.SqlServer.Management.Smo.Transfer]::new($databaseObject)
$transfer.CopyAllTables = $true
$transfer.CopyAllViews = $true
$transfer.CopyAllUserDefinedFunctions = $true
$transfer.CopyAllStoredProcedures = $true
$transfer.CopyAllDefaults = $true
$transfer.CopyAllRules = $true
$transfer.CopyAllUserDefinedDataTypes = $true
$transfer.CopyAllFullTextCatalogs = $false
$transfer.CopyAllFullTextStopLists = $false
$transfer.CopyAllSearchPropertyLists = $false
$transfer.CopySchema = $true
$transfer.CopyData = $false
$transfer.Options.ScriptSchema = $true
$transfer.Options.ScriptData = $false
$transfer.Options.DriAll = $true
$transfer.Options.Indexes = $true
$transfer.Options.Triggers = $true
$transfer.Options.FullTextIndexes = $false
$transfer.Options.IncludeDatabaseContext = $false
$transfer.Options.IncludeHeaders = $false
$transfer.Options.SchemaQualify = $true
$transfer.Options.NoCollation = $true

$lines = [System.Collections.Generic.List[string]]::new()
$lines.Add('-- Generated from the verified current e-Kabineti SQL Server schema.')
$lines.Add('-- The installer executes this only against a newly created empty database.')
$lines.Add('SET ANSI_NULLS ON;')
$lines.Add('SET QUOTED_IDENTIFIER ON;')
$lines.Add('SET XACT_ABORT ON;')
$lines.Add('GO')
foreach ($statement in $transfer.ScriptTransfer()) {
    $clean = $statement -replace "(?im)^USE \[[^\]]+\]\s*\r?\n?", ''
    $clean = $clean -replace ', OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF', ''
    if (-not [string]::IsNullOrWhiteSpace($clean)) {
        $lines.Add($clean.Trim())
        $lines.Add('GO')
    }
}

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$target = Join-Path $projectRoot $Output
New-Item -ItemType Directory -Path (Split-Path $target -Parent) -Force | Out-Null
[IO.File]::WriteAllLines($target, $lines, [Text.UTF8Encoding]::new($false))
Write-Output $target
