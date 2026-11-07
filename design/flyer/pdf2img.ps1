# Rendert Seiten einer PDF-Datei als PNG – mit dem in Windows eingebauten PDF-Renderer (Windows.Data.Pdf),
# ohne zusätzliche Programme.  Aufruf:
#   powershell -NoProfile -ExecutionPolicy Bypass -File pdf2img.ps1 <datei.pdf> <ausgabeordner> [breite_px] [seiten, z. B. 1 oder 1,2]
param(
    [Parameter(Mandatory)] [string] $Pdf,
    [Parameter(Mandatory)] [string] $OutDir,
    [int] $Width = 1600,
    [string] $Pages = ''
)
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Runtime.WindowsRuntime
$null = [Windows.Storage.StorageFile, Windows.Storage, ContentType = WindowsRuntime]
$null = [Windows.Data.Pdf.PdfDocument, Windows.Data.Pdf, ContentType = WindowsRuntime]
$null = [Windows.Storage.Streams.InMemoryRandomAccessStream, Windows.Storage.Streams, ContentType = WindowsRuntime]

$asTaskGeneric = ([System.WindowsRuntimeSystemExtensions].GetMethods() | Where-Object {
    $_.Name -eq 'AsTask' -and $_.GetParameters().Count -eq 1 -and $_.GetParameters()[0].ParameterType.Name -eq 'IAsyncOperation`1' })[0]
$asTaskAction = ([System.WindowsRuntimeSystemExtensions].GetMethods() | Where-Object {
    $_.Name -eq 'AsTask' -and $_.GetParameters().Count -eq 1 -and $_.GetParameters()[0].ParameterType.Name -eq 'IAsyncAction' })[0]
function Await($op, [Type] $t) { $task = $asTaskGeneric.MakeGenericMethod($t).Invoke($null, @($op)); $task.Wait(); $task.Result }
function AwaitAction($op) { $asTaskAction.Invoke($null, @($op)).Wait() }

$file = Await ([Windows.Storage.StorageFile]::GetFileFromPathAsync((Resolve-Path $Pdf).Path)) ([Windows.Storage.StorageFile])
$doc = Await ([Windows.Data.Pdf.PdfDocument]::LoadFromFileAsync($file)) ([Windows.Data.Pdf.PdfDocument])
New-Item -ItemType Directory -Force $OutDir | Out-Null
$list = if ($Pages) { $Pages.Split(',') | ForEach-Object { [int]$_ } } else { 1..$doc.PageCount }
$base = [IO.Path]::GetFileNameWithoutExtension($Pdf)
foreach ($n in $list) {
    $page = $doc.GetPage($n - 1)
    $opt = New-Object Windows.Data.Pdf.PdfPageRenderOptions
    $opt.DestinationWidth = $Width
    $opt.DestinationHeight = [uint32][math]::Round($Width * $page.Size.Height / $page.Size.Width)
    $stream = New-Object Windows.Storage.Streams.InMemoryRandomAccessStream
    AwaitAction ($page.RenderToStreamAsync($stream, $opt))
    $net = [System.IO.WindowsRuntimeStreamExtensions]::AsStreamForRead($stream.GetInputStreamAt(0))
    $out = Join-Path (Resolve-Path $OutDir).Path ('{0}-{1}.png' -f $base, $n)
    $fs = [IO.File]::Create($out); $net.CopyTo($fs); $fs.Close(); $net.Dispose(); $page.Dispose()
    Write-Output $out
}
Write-Output ('Seiten: {0}' -f $doc.PageCount)
$doc = $null; [GC]::Collect()
# PowerShell 5.1 stürzt sonst beim Beenden in der WinRT-Aufräumphase ab
[Environment]::Exit(0)
