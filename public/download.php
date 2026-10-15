<?php
declare(strict_types=1);

/* Auslieferung von PDF, JSON und ZIP – nur mit Berechtigung. QR: nur eigenes PDF, 24 h, dieses Gerät. */

require dirname(__DIR__) . '/app/bootstrap.php';

use Euedok\Auth;
use Euedok\Pdf;
use Euedok\QrAccess;
use Euedok\Reports;
use Euedok\Settings;
use Euedok\Storage;

function send_file(string $path, string $name, string $mime, bool $inline): never
{
    if (!is_file($path)) {
        http_response_code(404);
        exit('Datei nicht gefunden.');
    }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $name . '"');
    header('Cache-Control: private, no-store');
    readfile($path);
    exit;
}

$number = is_string($_GET['n'] ?? null) ? $_GET['n'] : '';
$format = ($_GET['f'] ?? 'pdf') === 'json' ? 'json' : 'pdf';

/* QR-Zugang: nur das eben abgeschlossene PDF */
if (!Auth::user() && isset($_GET['qr'])) {
    if ($format !== 'pdf' || !QrAccess::pdfAllowed($number)) {
        \Euedok\Log::warning('qr', 'download_denied', 'Download über QR verweigert', ['report' => $number, 'format' => $format]);
        http_response_code(403);
        exit('Dieses PDF ist über den QR-Zugang nicht (mehr) abrufbar.');
    }
    $meta = Reports::meta($number);
    $type = Settings::reportType($meta['type']) ?? ['label' => $meta['type_label']];
    send_file(Reports::pdfPath($meta), Reports::fileBase($type, $number) . '.pdf', 'application/pdf', true);
}

Auth::requireLogin();

/* ZIP-Export */
if (isset($_GET['zip'])) {
    $year = $_GET['zip'] === 'all' ? null : (int) $_GET['zip'];
    $tmp = tempnam(sys_get_temp_dir(), 'eud');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    $n = 0;
    foreach (glob(Storage::path('reports/' . ($year ?: '*') . '/*.{json,pdf}'), GLOB_BRACE) ?: [] as $f) {
        $zip->addFile($f, basename(dirname($f)) . '/' . basename($f));
        $n++;
    }
    if ($n === 0) {
        $zip->addFromString('LIESMICH.txt', "Keine Berichte vorhanden.\n");
    }
    $zip->close();
    \Euedok\Log::audit('reports', 'ZIP-Export ' . ($year ?: 'alle'));
    header('Content-Type: application/zip');
    header('Content-Length: ' . filesize($tmp));
    header('Content-Disposition: attachment; filename="EUEDOK_Berichte_' . ($year ?: 'alle') . '_' . date('Y-m-d') . '.zip"');
    readfile($tmp);
    @unlink($tmp);
    exit;
}

$meta = Reports::meta($number);
if (!$meta) {
    http_response_code(404);
    exit('Bericht nicht gefunden.');
}
$type = Settings::reportType($meta['type']) ?? ['label' => $meta['type_label']];
$base = Reports::fileBase($type, $number);

if ($format === 'json') {
    send_file(Storage::path('reports/' . $meta['file'] . '.json'), $base . '.json', 'application/json', false);
}

$pdf = Reports::pdfPath($meta);
if (!is_file($pdf)) {
    // Entwurf oder PDF fehlt: Vorschau erzeugen (nicht gespeichert bei Entwürfen)
    $r = Reports::load($number);
    if ($r['status'] === 'closed') {
        Pdf::writeReport($r, $pdf);
    } else {
        $tmp = Storage::path('cache/preview-' . bin2hex(random_bytes(4)) . '.pdf');
        Pdf::writeReport($r, $tmp);
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="Vorschau_' . $base . '.pdf"');
        readfile($tmp);
        @unlink($tmp);
        exit;
    }
}
send_file($pdf, $base . '.pdf', 'application/pdf', true);
