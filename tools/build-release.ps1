<#
  EÜDOK – Release bauen
  Erzeugt build\euedok-<Version>\ (public, app, vendor ohne Dev-Pakete) und build\euedok-<Version>.zip.
  Voraussetzung: sauberer Git-Stand, Tag v<Version> wird lokal gesetzt.

  Aufruf:  powershell -ExecutionPolicy Bypass -File tools\build-release.ps1 -Version 1.0.0
#>
param(
    [Parameter(Mandatory = $true)][string]$Version,
    [string]$Php = 'C:\xampp\php\php.exe'
)
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
Set-Location $root

if ($Version -notmatch '^\d+\.\d+\.\d+$') { throw "Version bitte als 1.2.3 angeben." }
if (git status --porcelain) { throw "Es gibt nicht committete Änderungen – bitte erst committen." }

# Version im Code muss passen
$boot = Get-Content app\bootstrap.php -Raw
if ($boot -notmatch "EUEDOK_VERSION = '$([regex]::Escape($Version))'") {
    throw "app\bootstrap.php hat eine andere EUEDOK_VERSION – bitte auf $Version setzen und committen."
}

$build = Join-Path $root 'build'
$target = Join-Path $build "euedok-$Version"
New-Item -ItemType Directory -Force $build | Out-Null
if (Test-Path $target) { Remove-Item -Recurse -Force $target }

# Nur versionierte Dateien aus dem aktuellen Commit
Write-Host "1/5 Dateien aus Git exportieren ..."
$tar = Join-Path $build "src-$Version.tar"
git archive --format=tar -o $tar HEAD public app composer.json composer.lock
New-Item -ItemType Directory -Force $target | Out-Null
tar -xf $tar -C $target
Remove-Item $tar

Write-Host "2/5 Bibliotheken installieren (ohne Dev-Pakete) ..."
$composer = Join-Path $build 'composer.phar'
if (-not (Test-Path $composer)) {
    Invoke-WebRequest 'https://getcomposer.org/download/latest-stable/composer.phar' -OutFile $composer -UseBasicParsing
}
Push-Location $target
& $Php $composer install --no-dev --optimize-autoloader --no-interaction --no-progress
if ($LASTEXITCODE -ne 0) { Pop-Location; throw "composer install fehlgeschlagen." }
Pop-Location
Remove-Item (Join-Path $target 'composer.json'), (Join-Path $target 'composer.lock')

Write-Host "3/5 Nicht benötigte PDF-Schriften entfernen (nur DejaVu bleibt) ..."
$fonts = Join-Path $target 'vendor\mpdf\mpdf\ttfonts'
Get-ChildItem $fonts -File | Where-Object { $_.Name -notlike 'DejaVu*' } | Remove-Item -Force

Write-Host "4/5 Prüfen: Syntax und Test-PDF ..."
Get-ChildItem $target -Recurse -Filter *.php | Where-Object { $_.FullName -notlike '*\vendor\*' } | ForEach-Object {
    $out = & $Php -l $_.FullName 2>&1
    if ($LASTEXITCODE -ne 0) { throw "Syntaxfehler: $out" }
}
$check = Join-Path $build "check-$Version.php"
@'
<?php
$_SERVER['SCRIPT_NAME'] = '/index.php';
require $argv[1] . '/app/bootstrap.php';
$r = ['report_number' => 'E-0000-001', 'type' => ['id' => 'incident', 'label' => 'Einsatz'], 'start' => '2026-01-01T10:00', 'end' => '2026-01-01T11:00',
  'duration_min' => 60, 'location' => 'Prüfort äöüß', 'subject' => 'Release-Prüfung', 'alert_source' => null, 'narrative' => 'Test', 'defects' => '',
  'equipment' => [], 'personnel' => [], 'lead' => null, 'custom_fields' => [], 'field_definitions' => [], 'status' => 'closed', 'history' => []];
\Euedok\Pdf::writeReport($r, $argv[2]);
file_put_contents($argv[3], \Euedok\Pdf::qrSticker('https://example.org/q/TEST', 'Prüfung'));
echo "ok\n";
'@ | Set-Content -Encoding UTF8 $check
$pdf1 = Join-Path $build "check-report.pdf"; $pdf2 = Join-Path $build "check-qr.pdf"
& $Php $check $target $pdf1 $pdf2
if ($LASTEXITCODE -ne 0 -or -not (Test-Path $pdf1) -or -not (Test-Path $pdf2)) { throw "Test-PDF konnte nicht erzeugt werden." }
Remove-Item $check, $pdf1, $pdf2
# Die Prüfung legt data\ im Build an – darf nicht mit ausgeliefert werden
if (Test-Path (Join-Path $target 'data')) { Remove-Item -Recurse -Force (Join-Path $target 'data') }

Write-Host "5/5 ZIP und Tag ..."
$zip = Join-Path $build "euedok-$Version.zip"
if (Test-Path $zip) { Remove-Item $zip }
Compress-Archive -Path (Join-Path $target '*') -DestinationPath $zip
if (-not (git tag -l "v$Version")) { git tag -a "v$Version" -m "EÜDOK $Version" }

$size = [math]::Round(((Get-ChildItem $target -Recurse -File | Measure-Object Length -Sum).Sum) / 1MB, 1)
Write-Host ""
Write-Host "Fertig: $target ($size MB)"
Write-Host "        $zip"
Write-Host "Deploy: powershell -ExecutionPolicy Bypass -File tools\deploy.ps1 -Version $Version"
