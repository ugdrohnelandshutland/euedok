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

$installed = \Euedok\Settings::isInstalled();
date_default_timezone_set($installed ? (string) (\Euedok\Settings::unit()['timezone'] ?? 'Europe/Berlin') : 'Europe/Berlin');

send_security_headers();
\Euedok\Auth::startSession();
