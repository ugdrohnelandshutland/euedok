<#
  EÜDOK – Deploy-Schlüssel einmalig auf dem Live-Server hinterlegen
  Hängt den öffentlichen Schlüssel an ~/.ssh/authorized_keys an (vorhandene Einträge bleiben)
  und prüft danach die Anmeldung mit dem Schlüssel. Das Passwort wird nur abgefragt, nie gespeichert.

  Aufruf:  powershell -ExecutionPolicy Bypass -File tools\setup-deploy-key.ps1
#>
param(
    [string]$SftpHost = 'ftp.example.org',
    [string]$User = 'deploy-user',
    [string]$KeyFile = "$env:USERPROFILE\.ssh\euedok_deploy",
    [string]$WinScp = 'C:\Program Files (x86)\WinSCP\WinSCP.com'
)
$ErrorActionPreference = 'Stop'
if (-not (Test-Path "$KeyFile.pub")) { throw "Öffentlicher Schlüssel fehlt: $KeyFile.pub" }
if (-not (Test-Path "$KeyFile.ppk")) { & $WinScp /keygen "$KeyFile" /output="$KeyFile.ppk" | Out-Null }
$pub = (Get-Content "$KeyFile.pub" -Raw).Trim()

$work = Join-Path $env:TEMP "euedok-key-$PID"
New-Item -ItemType Directory -Force $work | Out-Null
$auth = Join-Path $work 'authorized_keys'
$script = Join-Path $work 'script.txt'

function Run-WinScp([string]$text) {
    Set-Content -Path $script -Value $text -Encoding ASCII
    & $WinScp /script="$script" /loglevel=0 | Out-Host
    return $LASTEXITCODE
}

try {
    Write-Host "1/3 Anmelden mit Passwort und vorhandene authorized_keys holen ..." -ForegroundColor Yellow
    Write-Host "    (beim ersten Mal den Serverschlüssel mit „Ja“ bestätigen)"
    $code = Run-WinScp @"
option batch off
option confirm off
open sftp://$User@$SftpHost/
option batch continue
mkdir .ssh
get .ssh/authorized_keys "$auth"
exit
"@
    if ($code -ne 0) { throw "Anmeldung fehlgeschlagen." }

    $lines = @()
    if (Test-Path $auth) { $lines = Get-Content $auth | Where-Object { $_.Trim() -ne '' } }
    if ($lines -contains $pub) {
        Write-Host "2/3 Schlüssel ist schon hinterlegt." -ForegroundColor Green
    } else {
        $lines += $pub
        [IO.File]::WriteAllText($auth, (($lines -join "`n") + "`n"))
        Write-Host "2/3 Schlüssel hochladen (Passwort erneut eingeben) ..." -ForegroundColor Yellow
        $code = Run-WinScp @"
option batch off
option confirm off
open sftp://$User@$SftpHost/
option batch abort
put "$auth" .ssh/authorized_keys
chmod 700 .ssh
chmod 600 .ssh/authorized_keys
exit
"@
        if ($code -ne 0) { throw "Hochladen des Schlüssels fehlgeschlagen." }
    }

    Write-Host "3/3 Anmeldung mit Schlüssel testen ..." -ForegroundColor Yellow
    $code = Run-WinScp @"
option batch abort
option confirm off
open sftp://$User@$SftpHost/ -privatekey="$KeyFile.ppk"
pwd
exit
"@
    if ($code -eq 0) {
        Write-Host "Fertig: Deploy per Schlüssel funktioniert." -ForegroundColor Green
    } else {
        Write-Host "Schlüssel wurde hochgeladen, die Anmeldung damit klappt aber nicht." -ForegroundColor Red
        Write-Host "Vermutlich erlaubt der Tarif für diesen Benutzer nur Passwort-Anmeldung (Plesk → SSH-Zugang / SSH-Schlüssel prüfen)."
        exit 2
    }
} finally {
    Remove-Item -Recurse -Force $work -ErrorAction SilentlyContinue
}
