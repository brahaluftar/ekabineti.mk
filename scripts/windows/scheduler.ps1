param(
    [string]$PhpExe = "C:\xampp\php\php.exe",
    [string]$ProjectRoot = (Resolve-Path "$PSScriptRoot\..\..").Path,
    [string]$TaskName = 'EKabinet-Bitola-Scheduler'
)

$ErrorActionPreference = 'Stop'
$resolvedRoot = (Resolve-Path -LiteralPath $ProjectRoot).Path
$argument = "-NoProfile -WindowStyle Hidden -Command `"Set-Location -LiteralPath '$resolvedRoot'; `$env:EKABINETI_CONFIG='$resolvedRoot\php81\config\e-kabineti.php'; & '$PhpExe' php81\bin\scheduler.php`""
$action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument $argument
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 1)
$principal = New-ScheduledTaskPrincipal -UserId $env:USERNAME -LogonType S4U -RunLevel Limited
Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Principal $principal -Description 'е-Кабинет распоредувач – Општина Битола' -Force | Out-Null
Write-Host "Task-u $TaskName u regjistrua."
