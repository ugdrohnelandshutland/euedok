<#
  EÜDOK – Deploy auf den LIVE-Server (Netcup, FTPS = FTP mit expliziter TLS-Verschlüsselung, via WinSCP)
  Lädt public/, app/, vendor/ aus build\euedok-<Version>\ hoch. data/ auf dem Server wird NIE angefasst.
  Das Passwort wird beim Verbinden abgefragt und nirgends gespeichert.

  Vorschau (nichts wird geändert):
    powershell -ExecutionPolicy Bypass -File tools\deploy.ps1 -Version 1.0.0 -Preview
  Hochladen:
    powershell -ExecutionPolicy Bypass -File tools\deploy.ps1 -Version 1.0.0
#>
param(
    [Parameter(Mandatory = $true)][string]$Version,
    [string]$FtpHost = 'ftp.example.org',
    [string]$User = 'deploy-user',
    [string]$RemoteBase = '/euedok',
    [string]$WinScp = 'C:\Program Files (x86)\WinSCP\WinSCP.com',
    [switch]$Preview
)
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$src = Join-Path $root "build\euedok-$Version"
if (-not (Test-Path $src)) { throw "Release fehlt – zuerst: tools\build-release.ps1 -Version $Version" }
if (-not (Test-Path $WinScp)) { throw "WinSCP nicht gefunden: $WinScp" }

Write-Host "EÜDOK $Version → LIVE  ftpes://$User@$FtpHost$RemoteBase" -ForegroundColor Yellow
if ($Preview) { Write-Host "Vorschau – es wird nichts verändert." -ForegroundColor Cyan }
else {
    $ok = Read-Host "Wirklich auf das Live-System hochladen? (ja/nein)"
    if ($ok -ne 'ja') { Write-Host "Abgebrochen."; exit 1 }
}

$flag = if ($Preview) { ' -preview' } else { '' }
New-Item -ItemType Directory -Force (Join-Path $root 'build') | Out-Null
$log = Join-Path $root "build\deploy-$Version-$(Get-Date -Format yyyyMMdd-HHmmss).log"
# Vorschau legt keine Ordner an und läuft bei fehlenden Ordnern weiter
$mkdirs = if ($Preview) { '' } else { @"
option batch continue
mkdir "$RemoteBase"
mkdir "$RemoteBase/public"
mkdir "$RemoteBase/app"
mkdir "$RemoteBase/vendor"
"@ }
$script = @"
option batch off
option confirm off
open ftpes://$User@$FtpHost/ -passive=on
pwd
ls
$mkdirs
option batch $(if ($Preview) { 'continue' } else { 'abort' })
synchronize remote$flag -delete -criteria=size "$src\vendor" "$RemoteBase/vendor"
synchronize remote$flag -delete -criteria=size "$src\app" "$RemoteBase/app"
synchronize remote$flag -delete -criteria=size "$src\public" "$RemoteBase/public"
exit
"@
$scriptFile = Join-Path $env:TEMP "euedok-deploy-$PID.txt"
Set-Content -Path $scriptFile -Value $script -Encoding ASCII
try {
    # Passwort- und ggf. Zertifikats-Abfrage erscheinen hier in der Konsole
    & $WinScp /script="$scriptFile" /log="$log" /loglevel=0
    $code = $LASTEXITCODE
} finally {
    Remove-Item $scriptFile -ErrorAction SilentlyContinue
}
if ($code -ne 0) { Write-Host "Deploy fehlgeschlagen (Protokoll: $log)" -ForegroundColor Red; exit $code }

if ($Preview) {
    Write-Host "Vorschau fertig. Oben steht, was hochgeladen bzw. gelöscht würde." -ForegroundColor Green
} else {
    Write-Host "Deploy fertig. Protokoll: $log" -ForegroundColor Green
    Write-Host "Prüfen: https://dok.example.org/"
}
