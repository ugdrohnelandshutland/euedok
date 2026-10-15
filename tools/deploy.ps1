<#
  EÜDOK – Deploy auf den LIVE-Server (Netcup, SFTP via WinSCP)
  Lädt public/, app/, vendor/ aus build\euedok-<Version>\ hoch. data/ auf dem Server wird NIE angefasst.
  Anmeldung per Deploy-Schlüssel (~\.ssh\euedok_deploy.ppk, siehe tools\setup-deploy-key.ps1);
  ohne Schlüssel wird das Passwort beim Verbinden abgefragt und nirgends gespeichert.

  Vorschau (nichts wird geändert):
    powershell -ExecutionPolicy Bypass -File tools\deploy.ps1 -Version 1.0.0 -Preview
  Hochladen:
    powershell -ExecutionPolicy Bypass -File tools\deploy.ps1 -Version 1.0.0
#>
param(
    [Parameter(Mandatory = $true)][string]$Version,
    [string]$SftpHost = 'ftp.example.org',
    [string]$User = 'deploy-user',
    [string]$RemoteBase = '/euedok',
    [string]$WinScp = 'C:\Program Files (x86)\WinSCP\WinSCP.com',
    [string]$KeyFile = "$env:USERPROFILE\.ssh\euedok_deploy.ppk",
    [switch]$Preview,
    [switch]$Yes
)
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$src = Join-Path $root "build\euedok-$Version"
if (-not (Test-Path $src)) { throw "Release fehlt – zuerst: tools\build-release.ps1 -Version $Version" }
if (-not (Test-Path $WinScp)) { throw "WinSCP nicht gefunden: $WinScp" }

Write-Host "EÜDOK $Version → LIVE  sftp://$User@$SftpHost$RemoteBase" -ForegroundColor Yellow
if ($Preview) { Write-Host "Vorschau – es wird nichts verändert." -ForegroundColor Cyan }
elseif (-not $Yes) {
    $ok = Read-Host "Wirklich auf das Live-System hochladen? (ja/nein)"
    if ($ok -ne 'ja') { Write-Host "Abgebrochen."; exit 1 }
}

$flag = if ($Preview) { ' -preview' } else { '' }
$log = Join-Path $root "build\deploy-$Version-$(Get-Date -Format yyyyMMdd-HHmmss).log"
$useKey = Test-Path $KeyFile
$open = if ($useKey) { "option batch abort`nopen sftp://$User@$SftpHost/ -privatekey=`"$KeyFile`"" } else { "option batch off`nopen sftp://$User@$SftpHost/" }
Write-Host ($(if ($useKey) { 'Anmeldung per Schlüssel' } else { 'Anmeldung per Passwort (Abfrage folgt)' }))
$script = @"
option confirm off
$open
option batch continue
mkdir "$RemoteBase"
mkdir "$RemoteBase/public"
mkdir "$RemoteBase/app"
mkdir "$RemoteBase/vendor"
option batch abort
synchronize remote$flag -delete -criteria=size "$src\vendor" "$RemoteBase/vendor"
synchronize remote$flag -delete -criteria=size "$src\app" "$RemoteBase/app"
synchronize remote$flag -delete -criteria=size "$src\public" "$RemoteBase/public"
ls "$RemoteBase"
exit
"@
$scriptFile = Join-Path $env:TEMP "euedok-deploy-$PID.txt"
Set-Content -Path $scriptFile -Value $script -Encoding ASCII
try {
    # Passwort- und ggf. Hostschlüssel-Abfrage erscheinen hier in der Konsole
    & $WinScp /script="$scriptFile" /log="$log" /loglevel=0
    $code = $LASTEXITCODE
} finally {
    Remove-Item $scriptFile -ErrorAction SilentlyContinue
}
if ($code -ne 0) { Write-Host "Deploy fehlgeschlagen (Protokoll: $log)" -ForegroundColor Red; exit $code }

if (-not $Preview) {
    Write-Host "Deploy fertig. Protokoll: $log" -ForegroundColor Green
    Write-Host "Prüfen: https://dok.example.org/"
}
