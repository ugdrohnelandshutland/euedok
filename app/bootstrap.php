<?php
declare(strict_types=1);

/*
 * EÜDOK – gemeinsamer Einstieg für alle Seiten in public/.
 * Lädt Bibliotheken, setzt Pfade, startet die Sitzung.
 */

const EUEDOK_VERSION = '1.0.0';

define('EUEDOK_ROOT', dirname(__DIR__));
define('EUEDOK_DATA', EUEDOK_ROOT . '/data');

require EUEDOK_ROOT . '/vendor/autoload.php';
require __DIR__ . '/helpers.php';

mb_internal_encoding('UTF-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

set_exception_handler(static function (Throwable $e): void {
    \Euedok\Log::error('Unbehandelter Fehler: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
    }
    if (is_api_request()) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['ok' => false, 'error' => 'Interner Fehler. Details stehen im Fehlerprotokoll.']);
        return;
    }
    echo '<!doctype html><meta charset="utf-8"><title>Fehler</title>'
        . '<p style="font-family:system-ui;padding:24px">Interner Fehler. Bitte erneut versuchen. '
        . 'Details stehen im Fehlerprotokoll (data/logs/error.log).</p>';
});

$installed = \Euedok\Settings::isInstalled();
date_default_timezone_set($installed ? (string) (\Euedok\Settings::unit()['timezone'] ?? 'Europe/Berlin') : 'Europe/Berlin');

send_security_headers();
\Euedok\Auth::startSession();
