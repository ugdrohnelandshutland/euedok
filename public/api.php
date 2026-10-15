<?php
declare(strict_types=1);

/*
 * JSON-Schnittstelle des Formulars (Zwischenspeichern, Abschließen, Divera).
 * Angemeldete Zugänge oder QR-Sitzung – QR darf nur eigene, auf diesem Gerät begonnene Berichte bearbeiten.
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use Euedok\Actor;
use Euedok\Auth;
use Euedok\Divera;
use Euedok\QrAccess;
use Euedok\Reports;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'error' => 'Nur POST.'], 405);
}
csrf_check();

$user = Auth::user();
$qr = $user ? null : QrAccess::current();
if (!$user && !$qr) {
    json_out(['ok' => false, 'error' => 'Sitzung abgelaufen. Bitte neu anmelden bzw. QR-Code erneut scannen.'], 401);
}

$in = request_json();
$action = (string) ($_GET['action'] ?? '');
$number = is_string($in['n'] ?? null) ? $in['n'] : '';
$meta = Reports::meta($number);
if (!$meta) {
    json_out(['ok' => false, 'error' => 'Bericht nicht gefunden.'], 404);
}
if ($qr && !QrAccess::ownsReport($number)) {
    json_out(['ok' => false, 'error' => 'Kein Zugriff auf diesen Bericht.'], 403);
}

if ($user) {
    $actor = Actor::fromUser($user);
} else {
    $person = null;
    if (is_string($in['data']['created_by'] ?? null)) {
        $person = \Euedok\MasterData::findPerson($in['data']['created_by']);
    }
    $actor = QrAccess::actor($qr['entry'], $qr['device'], $person);
}
$data = is_array($in['data'] ?? null) ? $in['data'] : [];

switch ($action) {
    case 'save':
        json_out(Reports::saveDraft($number, $data, (int) ($in['rev'] ?? 0), $actor));

    case 'close':
        if ($qr) {
            if ($meta['status'] !== 'draft') {
                json_out(['ok' => false, 'error' => 'Korrekturen sind nur nach Anmeldung möglich.'], 403);
            }
            if (QrAccess::rateLimitReached($qr['entry'])) {
                json_out(['ok' => false, 'error' => 'Zu viele Berichte über diesen QR-Code in der letzten Stunde. Bitte später erneut versuchen oder anmelden.'], 429);
            }
        }
        $rev = array_key_exists('rev', $in) && $in['rev'] !== null ? (int) $in['rev'] : null;
        $res = Reports::close($number, $data, $rev, $actor, (bool) $qr);
        if ($res['ok']) {
            if ($qr) {
                QrAccess::grantPdf($number);
                $res['pdf_url'] = base_url('download.php?n=' . rawurlencode($number) . '&f=pdf&qr=1');
            } else {
                $res['pdf_url'] = base_url('download.php?n=' . rawurlencode($number) . '&f=pdf');
            }
        }
        unset($res['report'], $res['meta']);
        json_out($res);

    case 'divera_candidates':
        $source = Divera::lookupAllowed($meta['type'], (bool) $qr);
        if ($source === null) {
            json_out(['ok' => true, 'items' => [], 'window' => 0]);
        }
        try {
            json_out(['ok' => true, 'items' => Divera::candidates($source), 'window' => (int) \Euedok\Settings::divera()['window_hours']]);
        } catch (\Throwable $e) {
            \Euedok\Log::error('Divera: ' . $e->getMessage());
            json_out(['ok' => false, 'unreachable' => true, 'error' => 'Divera nicht erreichbar – bitte manuell ausfüllen.']);
        }

    case 'divera_import':
        $source = Divera::lookupAllowed($meta['type'], (bool) $qr);
        if ($source === null || ($in['source'] ?? '') !== $source) {
            json_out(['ok' => false, 'error' => 'Divera ist für diese Berichtsart nicht eingeschaltet.'], 400);
        }
        try {
            $import = Divera::importData($source, (string) ($in['id'] ?? ''));
        } catch (\Throwable $e) {
            \Euedok\Log::error('Divera: ' . $e->getMessage());
            json_out(['ok' => false, 'error' => 'Divera nicht erreichbar – bitte manuell ausfüllen.']);
        }
        if ($import === null) {
            json_out(['ok' => false, 'error' => 'Eintrag in Divera nicht mehr gefunden.']);
        }
        $res = Reports::applyDivera($number, $import, $actor);
        json_out($res);

    default:
        json_out(['ok' => false, 'error' => 'Unbekannte Aktion.'], 400);
}
