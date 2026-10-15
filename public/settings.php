<?php
declare(strict_types=1);

/* Einstellungsseite – einziger Weg, EÜDOK zu konfigurieren (Recht „Einstellungen ändern“). */

require dirname(__DIR__) . '/app/bootstrap.php';

use Euedok\Auth;
use Euedok\Divera;
use Euedok\Log;
use Euedok\Mailer;
use Euedok\Pdf;
use Euedok\QrAccess;
use Euedok\Reports;
use Euedok\Settings;
use Euedok\SettingsPage;

$user = Auth::requireSettingsRight();
$section = is_string($_GET['s'] ?? null) && isset(SettingsPage::SECTIONS[$_GET['s']]) ? $_GET['s'] : 'unit';
$action = (string) ($_GET['action'] ?? '');
$self = 'settings.php?s=' . $section;

/* QR-Aufkleber (nur direkt nach Anlegen/Erneuern – der Schlüssel liegt nur in dieser Sitzung) */
if (isset($_GET['sticker'])) {
    $id = (string) $_GET['sticker'];
    $key = $_SESSION['qr_keys'][$id] ?? null;
    $entry = QrAccess::find($id);
    if (!$key || !$entry) {
        flash('Die Druckvorlage gibt es nur direkt nach dem Anlegen oder Erneuern des Schlüssels.', 'warn');
        redirect('settings.php?s=qr');
    }
    $pdf = Pdf::qrSticker(absolute_url('q/' . $key), $entry['label']);
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="QR-Aufkleber_' . ascii_name($entry['slug']) . '.pdf"');
    header('Cache-Control: private, no-store');
    echo $pdf;
    exit;
}

$testResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    switch ($action) {
        case 'save':
            $msg = SettingsPage::save($section);
            flash($msg, str_contains($msg, 'Nichts gespeichert') || str_contains($msg, 'nicht gespeichert') ? 'err' : 'ok');
            redirect($self);

        case 'test-mail':
            $m = Settings::mail();
            if (!$m['recipients']) {
                flash('Keine Empfängeradresse hinterlegt.', 'err');
                redirect($self);
            }
            $res = Mailer::sendText($m['recipients'], '[EÜDOK] Testmail', "Diese Testmail wurde aus EÜDOK (" . Settings::unit()['name'] . ") von " . $user['display_name'] . " am " . date('d.m.Y H:i') . " gesendet.\n\nDer Mailversand funktioniert.");
            Log::audit('mail', 'Testmail ' . ($res['success'] ? 'erfolgreich' : 'fehlgeschlagen'));
            flash($res['success'] ? 'Testmail versendet an ' . implode(', ', $res['to']) . ($m['transport'] === 'file' ? ' (als Datei in data/mail-outbox/)' : '') : 'Testmail fehlgeschlagen: ' . $res['error'], $res['success'] ? 'ok' : 'err');
            redirect($self);

        case 'test-divera':
            try {
                $_SESSION['divera_test'] = ['ok' => true, 'data' => Divera::test(), 'at' => now_iso()];
            } catch (\Throwable $e) {
                $_SESSION['divera_test'] = ['ok' => false, 'error' => $e->getMessage(), 'at' => now_iso()];
            }
            redirect($self);

        case 'qr-create':
            $label = post_str('label');
            if ($label === '') {
                flash('Bitte eine Bezeichnung eintragen.', 'err');
                redirect($self);
            }
            [$entry, $key] = QrAccess::create($label, post_bool('divera'));
            $_SESSION['qr_keys'][$entry['id']] = $key;
            flash('QR-Zugang „' . $label . '“ angelegt. Link und Druckvorlage sind nur jetzt verfügbar.');
            redirect($self . '&new=' . $entry['id']);

        case 'qr-renew':
            $id = post_str('id');
            $key = QrAccess::renew($id);
            if ($key) {
                $_SESSION['qr_keys'][$id] = $key;
                flash('Neuer Schlüssel erzeugt – der alte Link und Aufkleber sind ab sofort ungültig.');
            }
            redirect($self . '&new=' . $id);

        case 'qr-toggle':
            $e = QrAccess::find(post_str('id'));
            if ($e) {
                QrAccess::update($e['id'], ['active' => !$e['active']], 'QR-Zugang ' . ($e['active'] ? 'gesperrt' : 'entsperrt') . ': ' . $e['label']);
                flash('QR-Zugang „' . $e['label'] . '“ ' . ($e['active'] ? 'gesperrt.' : 'entsperrt.'));
            }
            redirect($self);

        case 'qr-update':
            $e = QrAccess::find(post_str('id'));
            if ($e) {
                $label = post_str('label') ?: $e['label'];
                QrAccess::update($e['id'], ['label' => $label, 'divera_enabled' => post_bool('divera')], 'QR-Zugang geändert: ' . $label);
                $pin = post_str('pin');
                $days = (int) post_str('pin_remember_days', '30');
                if (post_bool('pin_off')) {
                    QrAccess::setPin($e['id'], null, $days);
                } elseif ($pin !== '') {
                    if (!preg_match('/^\d{4,6}$/', $pin)) {
                        flash('Die PIN muss aus 4 bis 6 Ziffern bestehen.', 'err');
                        redirect($self);
                    }
                    QrAccess::setPin($e['id'], $pin, $days);
                } elseif ((int) $e['pin_remember_days'] !== $days) {
                    QrAccess::update($e['id'], ['pin_remember_days' => max(0, $days)], 'PIN-Merkdauer geändert: ' . $label);
                }
                flash('QR-Zugang „' . $label . '“ gespeichert.');
            }
            redirect($self);

        case 'qr-delete':
            QrAccess::delete(post_str('id'));
            flash('QR-Zugang gelöscht – der Link ist ungültig.');
            redirect($self);

        case 'rebuild-index':
            $n = Reports::rebuildIndex();
            Log::audit('reports', 'Index neu aufgebaut (' . $n . ' Berichte)');
            flash('Übersicht neu aufgebaut: ' . $n . ' Berichte.');
            redirect($self);
    }
}

render('partials/head', ['title' => 'Einstellungen', 'nav' => 'settings']);
render('settings', ['section' => $section, 'user' => $user]);
render('partials/foot', ['nav' => 'settings']);
