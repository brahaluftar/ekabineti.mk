param(
    [string]$PhpExe = "C:\xampp\php\php.exe",
    [string]$ProjectRoot = (Resolve-Path "$PSScriptRoot\..\..").Path,
    [switch]$RunMigrations
)

$ErrorActionPreference = 'Stop'
if (-not (Test-Path -LiteralPath $PhpExe)) { throw "PHP не е пронајден: $PhpExe" }
$resolvedRoot = (Resolve-Path -LiteralPath $ProjectRoot).Path
$version = & $PhpExe -r "echo PHP_VERSION;"
if ([version]$version -lt [version]'8.1.0' -or [version]$version -ge [version]'8.4.0') { throw "Потребен е PHP 8.1–8.3; пронајден е $version" }

$required = @('curl','fileinfo','intl','mbstring','openssl','pdo_sqlsrv','sqlsrv','sodium','zip')
$modules = & $PhpExe -m
$missing = $required | Where-Object { $_ -notin $modules }
if ($missing) { throw "Недостасуваат проширувања: $($missing -join ', ')" }

foreach ($path in @('php81\storage','php81\storage\logs','php81\storage\private')) {
    $target = Join-Path $resolvedRoot $path
    if (-not (Test-Path -LiteralPath $target)) { throw "Mungon direktoria: $target" }
    $probe = Join-Path $target ('.write-test-' + [guid]::NewGuid().ToString('N'))
    [IO.File]::WriteAllText($probe, 'ok')
    Remove-Item -LiteralPath $probe -Force
}

Push-Location $resolvedRoot
try {
    $configPath = Join-Path $resolvedRoot 'php81\config\e-kabineti.php'
    if (-not (Test-Path -LiteralPath $configPath)) { throw "Mungon konfigurimi privat i PHP 8.1: $configPath" }
    $env:EKABINETI_CONFIG = $configPath
    & $PhpExe php81\bin\preflight.php --static-only
    if ($RunMigrations) { & $PhpExe php81\bin\install-database.php }
    & $PhpExe php81\bin\heartbeat.php
} finally { Pop-Location }

Write-Host "OK: PHP $version, проширувањата, запишувањето, SQL Server и heartbeat се исправни." -ForegroundColor Green
