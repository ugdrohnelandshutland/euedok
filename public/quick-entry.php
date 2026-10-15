<?php
declare(strict_types=1);

/*
 * QR-Schnellerfassung (Route /q/<key>): ohne Anmeldung direkt zu „Neuer Bericht“.
 * Erlaubt nur: neu erstellen, eigenen Entwurf auf diesem Gerät fortsetzen, abschließen, eigenes PDF (24 h).
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use Euedok\Divera;
use Euedok\FormConfig;
use Euedok\Log;
use Euedok\QrAccess;
use Euedok\Reports;
use Euedok\Settings;

if (!Settings::isInstalled()) {
    http_response_code(404);
    exit;
}

function qr_page(string $title, string $view, array $vars = []): never
{
    render('partials/head', ['title' => $title, 'plain' => true]);
    render($view, $vars);
    render('partials/foot');
    exit;
}

function qr_fail(string $text): never
{
    Log::info('qr', 'denied', $text, ['path' => $_SERVER['REQUEST_URI'] ?? null]);
    http_response_code(403);
    qr_page('Schnellerfassung', 'quick-message', ['text' => $text]);
}

/* 1) Link mit Schlüssel geöffnet */
$key = is_string($_GET['key'] ?? null) ? $_GET['key'] : '';
if ($key !== '') {
    $entry = QrAccess::findByKey($key);
    if (!$entry || empty($entry['active'])) {
        Log::warning('qr', 'invalid_key', $entry ? 'Gesperrter QR-Zugang aufgerufen' : 'Unbekannter QR-Schlüssel aufgerufen', [
            'qr' => $entry['label'] ?? null, 'key_hint' => substr($key, 0, 4), 'user_agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 160),
        ]);
        qr_fail('Dieser QR-Code ist nicht (mehr) gültig. Bitte bei der Leitung melden.');
    }
    $device = QrAccess::deviceId();
    if (!empty($entry['pin_enabled']) && !QrAccess::pinRemembered($entry, $device)) {
        $_SESSION['qr_pending'] = $entry['id'];
        Log::info('qr', 'pin_required', 'QR geöffnet, PIN-Abfrage', ['qr' => $entry['label'], 'device' => substr($device, 0, 12)]);
        redirect('quick-entry.php?pin=1');
    }
    Log::info('qr', 'opened', 'QR-Schnellerfassung geöffnet', ['qr' => $entry['label'], 'device' => substr($device, 0, 12)]);
    QrAccess::startSession($entry, $device);
    redirect('quick-entry.php');
}

/* 2) PIN-Abfrage */
if (isset($_GET['pin'])) {
    $entry = isset($_SESSION['qr_pending']) ? QrAccess::find((string) $_SESSION['qr_pending']) : null;
    if (!$entry || empty($entry['active'])) {
        qr_fail('Bitte den QR-Code erneut scannen.');
    }
    $device = QrAccess::deviceId();
    $error = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $error = QrAccess::checkPin($entry, $device, post_str('pin'));
        if ($error === null) {
            unset($_SESSION['qr_pending']);
            QrAccess::startSession($entry, $device);
            redirect('quick-entry.php');
        }
    }
    $locked = QrAccess::pinLockedUntil($entry, $device);
    qr_page('PIN', 'quick-pin', ['entry' => $entry, 'error' => $error, 'locked' => $locked]);
}

/* 3) Ab hier nur mit QR-Sitzung (oder angemeldet) */
$ctx = QrAccess::current();
if (!$ctx) {
    qr_fail('Sitzung abgelaufen. Bitte den QR-Code erneut scannen.');
}
$entry = $ctx['entry'];
$device = $ctx['device'];

if (($_GET['action'] ?? '') === 'start' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $type = post_str('type');
    if (!Settings::reportType($type)) {
        qr_fail('Unbekannte Berichtsart.');
    }
    $report = Reports::create($type, QrAccess::actor($entry, $device));
    redirect('quick-entry.php?n=' . rawurlencode($report['report_number']) . '&new=1');
}

$number = is_string($_GET['n'] ?? null) ? $_GET['n'] : '';
if ($number !== '') {
    if (!QrAccess::ownsReport($number)) {
        qr_fail('Dieser Bericht ist über den QR-Zugang nicht abrufbar.');
    }
    $report = Reports::load($number);
    $meta = Reports::meta($number);
    if ($report['status'] !== 'draft') {
        redirect('quick-entry.php?new=1');
    }
    $cfg = FormConfig::build($report, $meta, [
        'mode' => 'qr',
        'ask_divera' => isset($_GET['new']) && empty($report['divera']) && !empty($entry['divera_enabled']),
        'divera_lookup' => !empty($entry['divera_enabled']) ? Divera::lookupAllowed($report['type']['id'], true) : null,
        'home' => base_url('quick-entry.php?new=1'),
        'back' => base_url('quick-entry.php?new=1'),
        'restart' => base_url('quick-entry.php?new=1'),
    ]);
    render('partials/head', ['title' => $number, 'bodyClass' => 'report', 'plain' => true, 'scripts' => ['js/report.js']]);
    render('report-form', ['cfg' => $cfg, 'r' => $report, 'qrLabel' => $entry['label']]);
    render('partials/foot', ['noBottomNav' => true]);
    exit;
}

$draft = QrAccess::openDraft($entry, $device);
if ($draft && !isset($_GET['new'])) {
    redirect('quick-entry.php?n=' . rawurlencode($draft));
}
qr_page('Schnellerfassung', 'quick-start', ['entry' => $entry, 'draft' => $draft]);
