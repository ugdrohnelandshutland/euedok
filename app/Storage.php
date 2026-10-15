<?php
declare(strict_types=1);

namespace Euedok;

/**
 * Liest und schreibt JSON-Dateien unter data/ – atomar und mit Dateisperre,
 * damit sich zwei gleichzeitige Bearbeiter nicht überschreiben.
 */
final class Storage
{
    public static function path(string $relative): string
    {
        return EUEDOK_DATA . '/' . ltrim($relative, '/');
    }

    public static function read(string $relative, mixed $default = null): mixed
    {
        $file = self::path($relative);
        if (!is_file($file)) {
            return $default;
        }
        $fp = fopen($file, 'rb');
        if ($fp === false) {
            return $default;
        }
        try {
            flock($fp, LOCK_SH);
            $raw = stream_get_contents($fp);
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
        $data = json_decode((string) $raw, true);
        return $data ?? $default;
    }

    public static function write(string $relative, mixed $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('JSON konnte nicht erzeugt werden: ' . json_last_error_msg());
        }
        self::writeRaw($relative, $json . "\n");
    }

    public static function writeRaw(string $relative, string $contents): void
    {
        $file = self::path($relative);
        self::ensureDir(dirname($file));
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $contents, LOCK_EX) === false) {
            throw new \RuntimeException('Datei konnte nicht geschrieben werden: ' . $relative);
        }
        if (!@rename($tmp, $file)) {
            // Windows: Ziel ggf. gesperrt – direkt überschreiben
            $ok = file_put_contents($file, $contents, LOCK_EX) !== false;
            @unlink($tmp);
            if (!$ok) {
                throw new \RuntimeException('Datei konnte nicht ersetzt werden: ' . $relative);
            }
        }
    }

    /** Führt $fn exklusiv aus (ein Sperr-Name je Ressource). */
    public static function locked(string $name, callable $fn): mixed
    {
        $dir = self::path('locks');
        self::ensureDir($dir);
        $fp = fopen($dir . '/' . preg_replace('/[^a-z0-9._-]/i', '_', $name) . '.lock', 'c');
        if ($fp === false) {
            throw new \RuntimeException('Sperrdatei nicht verfügbar');
        }
        try {
            flock($fp, LOCK_EX);
            return $fn();
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    public static function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Ordner konnte nicht angelegt werden: ' . $dir);
        }
    }

    public static function append(string $relative, string $line): void
    {
        $file = self::path($relative);
        self::ensureDir(dirname($file));
        file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
    }
}
