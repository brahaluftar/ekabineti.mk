param(
    [string]$Version = "2026.09.25.01",
    [string]$OutputDirectory = "dist"
)

$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$source = Join-Path $projectRoot 'php81'
$output = Join-Path $projectRoot $OutputDirectory
$stage = Join-Path $env:TEMP ("e-kabineti-php81-" + [guid]::NewGuid().ToString('N'))
$releaseName = "e-kabineti-php81-$Version"
$releaseStage = Join-Path $stage $releaseName

$stageFull = [System.IO.Path]::GetFullPath($stage)
$tempFull = [System.IO.Path]::GetFullPath([System.IO.Path]::GetTempPath())
if (!$stageFull.StartsWith($tempFull, [System.StringComparison]::OrdinalIgnoreCase)) {
    throw "Refusing to use a staging directory outside the system temp path: $stageFull"
}

try {
    New-Item -ItemType Directory -Path $releaseStage -Force | Out-Null
    New-Item -ItemType Directory -Path $output -Force | Out-Null
    Copy-Item -Path (Join-Path $source '*') -Destination $releaseStage -Recurse -Force
    $releaseEnvExample = Join-Path $releaseStage '.env.example'
    if (Test-Path -LiteralPath $releaseEnvExample) { Remove-Item -LiteralPath $releaseEnvExample -Force }
    $releaseTests = Join-Path $releaseStage 'tests'
    if (Test-Path -LiteralPath $releaseTests) { Remove-Item -LiteralPath $releaseTests -Recurse -Force }
    $releaseStorage = Join-Path $releaseStage 'storage'
    if (Test-Path -LiteralPath $releaseStorage) { Remove-Item -LiteralPath $releaseStorage -Recurse -Force }
    $releaseLogs = Join-Path $releaseStorage 'logs'
    $releasePrivate = Join-Path $releaseStorage 'private'
    New-Item -ItemType Directory -Path $releaseLogs -Force | Out-Null
    New-Item -ItemType Directory -Path $releasePrivate -Force | Out-Null
    [System.IO.File]::WriteAllText((Join-Path $releaseLogs '.keep'), '', [System.Text.UTF8Encoding]::new($false))
    [System.IO.File]::WriteAllText((Join-Path $releasePrivate '.keep'), '', [System.Text.UTF8Encoding]::new($false))

    $releaseMarker = (Get-Content -LiteralPath (Join-Path $releaseStage 'RELEASE') -Raw).Trim()
    if ($releaseMarker -ne $Version) {
        throw "RELEASE marker '$releaseMarker' does not match package version '$Version'."
    }

    $privateConfig = Join-Path $releaseStage 'config\e-kabineti.php'
    if (Test-Path -LiteralPath $privateConfig) {
        throw "Refusing to package a private production configuration: $privateConfig"
    }

    $zip = Join-Path $output "$releaseName.zip"
    if (Test-Path -LiteralPath $zip) { Remove-Item -LiteralPath $zip -Force }

    Add-Type -AssemblyName System.IO.Compression
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $zipStream = [System.IO.File]::Open($zip, [System.IO.FileMode]::CreateNew, [System.IO.FileAccess]::ReadWrite, [System.IO.FileShare]::None)
    try {
        $archive = [System.IO.Compression.ZipArchive]::new($zipStream, [System.IO.Compression.ZipArchiveMode]::Create, $true)
        try {
            foreach ($file in Get-ChildItem -LiteralPath $releaseStage -Recurse -File | Sort-Object FullName) {
                $relative = $file.FullName.Substring($releaseStage.Length)
                $entryName = (($relative -replace '^[\\/]+', '') -replace '\\', '/')
                if ($entryName.Contains('\')) { throw "Backslash remained in ZIP entry: $entryName" }
                if ($entryName -match '(^/|^[A-Za-z]:)' -or ($entryName -split '/') -contains '..') {
                    throw "Unsafe ZIP entry: $entryName"
                }
                [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
                    $archive,
                    $file.FullName,
                    $entryName,
                    [System.IO.Compression.CompressionLevel]::Optimal
                ) | Out-Null
            }
        } finally {
            $archive.Dispose()
        }
    } finally {
        $zipStream.Dispose()
    }

    $verification = [System.IO.Compression.ZipFile]::OpenRead($zip)
    try {
        $entryNames = @($verification.Entries | ForEach-Object { $_.FullName })
        if ($entryNames.Count -eq 0) { throw 'Release ZIP is empty.' }
        $backslashEntries = @($entryNames | Where-Object { $_.Contains('\') })
        if ($backslashEntries.Count -gt 0) { throw "Release ZIP contains backslashes: $($backslashEntries -join ', ')" }
        $unsafeEntries = @($entryNames | Where-Object { $_ -match '(^/|^[A-Za-z]:)' -or (($_ -split '/') -contains '..') })
        if ($unsafeEntries.Count -gt 0) { throw "Release ZIP contains unsafe paths: $($unsafeEntries -join ', ')" }
        $protectedEntries = @($entryNames | Where-Object { $_ -eq '.env' -or $_ -match '(^|/)\.env($|\.)' -or $_ -eq 'config/e-kabineti.php' })
        if ($protectedEntries.Count -gt 0) { throw "Release ZIP contains protected configuration: $($protectedEntries -join ', ')" }
    } finally {
        $verification.Dispose()
    }

    $hash = (Get-FileHash -LiteralPath $zip -Algorithm SHA256).Hash.ToLowerInvariant()
    $checksum = "$hash  $releaseName.zip`n"
    $checksumPath = "$zip.sha256"
    [System.IO.File]::WriteAllText($checksumPath, $checksum, [System.Text.UTF8Encoding]::new($false))
    Write-Output $zip
    Write-Output $checksumPath
} finally {
    if (Test-Path -LiteralPath $stageFull) { Remove-Item -LiteralPath $stageFull -Recurse -Force }
}
