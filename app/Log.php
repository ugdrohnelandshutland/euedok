<?php
declare(strict_types=1);

namespace Euedok;

/**
 * Protokolle für die ganze Anwendung (data/logs/<kanal>.log).
 * Eine JSON-Zeile je Eintrag, Rotation bei 20 MB, 5 alte Dateien bleiben.
 * Geheimnisse (Passwörter, PIN, API-Schlüssel) werden vor dem Schreiben unkenntlich gemacht.
 */
final class Log
{
    public const MAX_BYTES = 20 * 1024 * 1024;
    public const KEEP = 5;
    public const RAW_DAYS = 30;

    /** Kanal → Bezeichnung in der Oberfläche */
    public const CHANNELS = [
        'app' => 'Anwendung',
        'auth' => 'Anmeldung',
        'report' => 'Berichte',
        'mail' => 'Mailversand',
        'pdf' => 'PDF',
        'divera' => 'Divera247',
        'qr' => 'QR-Schnellerfassung',
        'update' => 'Update',
        'error' => 'Fehler',
    ];

    private const LEVELS = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];

    private static ?string $requestId = null;
    private static bool $writing = false;

    public static function requestId(): string
    {
        return self::$requestId ??= bin2hex(random_bytes(4));
    }

    public static function debug(string $channel, string $event, string $message = '', array $context = []): void
    {
        self::write($channel, 'debug', $event, $message, $context);
    }

    public static function info(string $channel, string $event, string $message = '', array $context = []): void
    {
        self::write($channel, 'info', $event, $message, $context);
    }

    public static function warning(string $channel, string $event, string $message = '', array $context = []): void
    {
        self::write($channel, 'warning', $event, $message, $context);
    }

    public static function error(string $channel, string $event, string $message = '', array $context = []): void
    {
        self::write($channel, 'error', $event, $message, $context);
        if ($channel !== 'error') {
            // Alle Fehler zusätzlich gesammelt im Kanal „error“
            self::write('error', 'error', $channel . '.' . $event, $message, $context);
        }
    }

    public static function minLevel(): string
    {
        try {
            $l = Settings::isInstalled() ? (string) (Settings::unit()['log_level'] ?? 'info') : 'info';
        } catch (\Throwable) {
            $l = 'info';
        }
        return isset(self::LEVELS[$l]) ? $l : 'info';
    }

    public static function write(string $channel, string $level, string $event, string $message = '', array $context = []): void
    {
        if (self::$writing || self::LEVELS[$level] < self::LEVELS[self::minLevel()]) {
            return;
        }
        self::$writing = true;
        try {
            $user = null;
            try {
                $user = Auth::user()['username'] ?? (isset($_SESSION['qr']) ? 'qr' : null);
            } catch (\Throwable) {
            }
            $row = [
                'at' => (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.v'),
                'level' => $level,
                'channel' => $channel,
                'event' => $event,
                'message' => self::redactString($message),
                'request' => self::requestId(),
                'user' => $user,
                'ip' => PHP_SAPI === 'cli' ? 'cli' : client_ip(),
            ];
            if ($context) {
                $row['context'] = self::redact($context);
            }
            $line = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
            self::append('logs/' . preg_replace('/[^a-z0-9-]/', '', $channel) . '.log', (string) $line);
        } catch (\Throwable $e) {
            error_log('EÜDOK Log: ' . $e->getMessage() . ' | ' . $message);
        } finally {
            self::$writing = false;
        }
    }

    /** Anhängen mit Rotation: x.log → x.log.1 → … → x.log.5 */
    private static function append(string $relative, string $line): void
    {
        $file = Storage::path($relative);
        Storage::ensureDir(dirname($file));
        clearstatcache(true, $file);
        if (is_file($file) && filesize($file) + strlen($line) + 1 > self::MAX_BYTES) {
            Storage::locked('log-rotate', static function () use ($file) {
                clearstatcache(true, $file);
                if (!is_file($file) || filesize($file) < self::MAX_BYTES - 65536) {
                    return; // anderer Aufruf hat schon rotiert
                }
                @unlink($file . '.' . self::KEEP);
                for ($i = self::KEEP - 1; $i >= 1; $i--) {
                    if (is_file($file . '.' . $i)) {
                        @rename($file . '.' . $i, $file . '.' . ($i + 1));
                    }
                }
                @rename($file, $file . '.1');
            });
        }
        file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    /* ---------- Schwärzen ---------- */

    private const SECRET_KEYS = '/pass|secret|token|pin|accesskey|api_key|apikey|key_hash|password|validator|csrf|cookie/i';

    public static function redact(array $data): array
    {
        foreach ($data as $k => $v) {
            if (is_string($k) && preg_match(self::SECRET_KEYS, $k) && $v !== null && $v !== '' && !is_bool($v)) {
                $data[$k] = '***';
            } elseif (is_array($v)) {
                $data[$k] = self::redact($v);
            } elseif (is_string($v)) {
                $data[$k] = self::redactString($v);
            }
        }
        return $data;
    }

    public static function redactString(string $s): string
    {
        $s = (string) preg_replace('/(accesskey=)[^&\s"\']+/i', '$1***', $s);
        // QR-Links /q/<schlüssel>
        return (string) preg_replace('#(/q/)[0-9A-Za-z-]{20,64}#', '$1***', $s);
    }

    /* ---------- Rohdaten (Divera) ---------- */

    /** Rohantwort für spätere Analyse ablegen (enthält Namen → nur bei Fehlern/Übernahmen, 30 Tage). */
    public static function raw(string $name, string $body): ?string
    {
        try {
            $dir = 'logs/divera-raw';
            Storage::ensureDir(Storage::path($dir));
            $file = date('Ymd-His') . '-' . self::requestId() . '-' . preg_replace('/[^a-z0-9-]/', '-', strtolower($name)) . '.json';
            Storage::writeRaw($dir . '/' . $file, self::redactString(substr($body, 0, 5 * 1024 * 1024)));
            if (random_int(1, 20) === 1) {
                self::pruneRaw();
            }
            return $file;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function pruneRaw(): void
    {
        $limit = time() - self::RAW_DAYS * 86400;
        foreach (glob(Storage::path('logs/divera-raw/*.json')) ?: [] as $f) {
            if (filemtime($f) < $limit) {
                @unlink($f);
            }
        }
    }

    /* ---------- Lesen ---------- */

    /** Letzte Einträge eines Kanals (liest nur das Dateiende). */
    public static function tail(string $channel, int $limit = 200, ?string $minLevel = null, string $search = ''): array
    {
        $file = Storage::path('logs/' . preg_replace('/[^a-z0-9-]/', '', $channel) . '.log');
        if (!is_file($file)) {
            return [];
        }
        $size = filesize($file);
        $chunk = min($size, 2 * 1024 * 1024);
        $fp = fopen($file, 'rb');
        fseek($fp, $size - $chunk);
        $data = (string) fread($fp, $chunk);
        fclose($fp);
        $lines = explode("\n", $data);
        if ($chunk < $size) {
            array_shift($lines); // erste Zeile ist abgeschnitten
        }
        $out = [];
        $min = $minLevel ? (self::LEVELS[$minLevel] ?? 0) : 0;
        for ($i = count($lines) - 1; $i >= 0 && count($out) < $limit; $i--) {
            $row = json_decode($lines[$i], true);
            if (!is_array($row) || (self::LEVELS[$row['level'] ?? 'info'] ?? 1) < $min) {
                continue;
            }
            if ($search !== '' && !str_contains(mb_strtolower($lines[$i]), mb_strtolower($search))) {
                continue;
            }
            $out[] = $row;
        }
        return $out;
    }

    /** Alle Protokolldateien (für den ZIP-Download). */
    public static function files(): array
    {
        return array_merge(
            glob(Storage::path('logs/*.log*')) ?: [],
            glob(Storage::path('logs/divera-raw/*.json')) ?: []
        );
    }

    /* ---------- Änderungsprotokoll der Einstellungen ---------- */

    /** Wer, wann, was – eigene Datei, gleiche Rotation. */
    public static function audit(string $area, string $what): void
    {
        $user = Auth::user();
        $line = json_encode([
            'at' => now_iso(),
            'by' => $user['username'] ?? 'setup',
            'area' => $area,
            'change' => $what,
            'ip' => PHP_SAPI === 'cli' ? 'cli' : client_ip(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        self::append('logs/settings-audit.log', (string) $line);
        self::info('app', 'settings_changed', $area . ': ' . $what);
    }

    public static function readAudit(int $limit = 200): array
    {
        $file = Storage::path('logs/settings-audit.log');
        if (!is_file($file)) {
            return [];
        }
        $lines = array_slice(file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [], -$limit);
        $out = [];
        foreach (array_reverse($lines) as $l) {
            $row = json_decode($l, true);
            if (is_array($row)) {
                $out[] = $row;
            }
        }
        return $out;
    }
}
