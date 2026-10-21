<?php
declare(strict_types=1);

/*
 * EÜDOK – gemeinsamer Einstieg für alle Seiten in public/.
 * Lädt Bibliotheken, setzt Pfade, startet die Sitzung.
 */

const EUEDOK_VERSION = '1.4.0';

define('EUEDOK_ROOT', dirname(__DIR__));
define('EUEDOK_DATA', EUEDOK_ROOT . '/data');

require EUEDOK_ROOT . '/vendor/autoload.php';
require __DIR__ . '/helpers.php';

mb_internal_encoding('UTF-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

define('EUEDOK_START', microtime(true));

set_exception_handler(static function (Throwable $e): void {
    \Euedok\Log::error('app', 'uncaught_exception', $e->getMessage(), [
        'type' => get_class($e),
        'file' => $e->getFile() . ':' . $e->getLine(),
        'path' => $_SERVER['REQUEST_URI'] ?? null,
        'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 15),
    ]);
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

// PHP-Warnungen und -Hinweise ins Protokoll statt auf die Seite
set_error_handler(static function (int $no, string $msg, string $file, int $line): bool {
    if (!(error_reporting() & $no)) {
        return false;
    }
    $level = in_array($no, [E_WARNING, E_USER_WARNING, E_CORE_WARNING, E_COMPILE_WARNING], true) ? 'warning'
        : (in_array($no, [E_USER_ERROR, E_RECOVERABLE_ERROR], true) ? 'error' : 'debug');
    \Euedok\Log::write('error', $level, 'php_' . $level, $msg, ['file' => $file . ':' . $line, 'path' => $_SERVER['REQUEST_URI'] ?? null]);
    return true;
});

register_shutdown_function(static function (): void {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        \Euedok\Log::write('error', 'error', 'php_fatal', $err['message'], ['file' => $err['file'] . ':' . $err['line'], 'path' => $_SERVER['REQUEST_URI'] ?? null]);
    }
    // Jede Anfrage nur in der Stufe „Debug“ (sonst zu viel)
    if (PHP_SAPI !== 'cli') {
        \Euedok\Log::debug('app', 'request', ($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), [
            'status' => http_response_code(),
            'ms' => (int) round((microtime(true) - EUEDOK_START) * 1000),
            'memory_mb' => round(memory_get_peak_usage() / 1048576, 1),
        ]);
    }
});

// Schutz: Läuft EÜDOK unter …/public/, zeigt der Dokumentstamm auf den Projektordner –
// dann wären data/ (Berichte, Schlüssel) und app/ per URL erreichbar. Lokal (localhost) erlaubt.
$reqHost = strtolower((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
if (PHP_SAPI !== 'cli' && str_ends_with(rtrim(base_url(), '/'), '/public') && !in_array($reqHost, ['localhost', '127.0.0.1', '::1', '[::1]'], true)) {
    \Euedok\Log::error('app', 'docroot_wrong', 'Dokumentstamm zeigt nicht auf public/ – Start verweigert', ['path' => $_SERVER['REQUEST_URI'] ?? null]);
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>EÜDOK – Einrichtung unvollständig</title>'
        . '<div style="font-family:system-ui;max-width:560px;margin:40px auto;padding:0 16px;line-height:1.5">'
        . '<h1>Einrichtung unvollständig</h1><p>Der Dokumentstamm der Domain zeigt auf den Projektordner. '
        . 'Damit wären Berichte und Schlüssel öffentlich erreichbar, deshalb startet EÜDOK nicht.</p>'
        . '<p><b>Lösung:</b> In Plesk unter <i>Hosting-Einstellungen → Dokumentstamm</i> den Ordner <code>public</code> eintragen.</p></div>';
    exit;
}

// Während ein Update die Programmordner tauscht (wenige Sekunden): kurz „Update läuft“ statt halber Seiten
if (PHP_SAPI !== 'cli' && \Euedok\Updater::maintenanceActive()) {
    http_response_code(503);
    header('Retry-After: 30');
    if (is_api_request()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'EÜDOK wird gerade aktualisiert. Bitte in einer Minute erneut versuchen.']);
        exit;
    }
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>EÜDOK – Update läuft</title>'
        . '<div style="font-family:system-ui;max-width:560px;margin:40px auto;padding:0 16px;line-height:1.5">'
        . '<h1>Update läuft</h1><p>EÜDOK wird gerade aktualisiert. Bitte in einer Minute neu laden.</p></div>';
    exit;
}

$installed = \Euedok\Settings::isInstalled();
date_default_timezone_set($installed ? (string) (\Euedok\Settings::unit()['timezone'] ?? 'Europe/Berlin') : 'Europe/Berlin');

send_security_headers();
\Euedok\Auth::startSession();
