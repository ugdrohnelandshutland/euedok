<?php
declare(strict_types=1);

namespace Euedok;

final class Log
{
    public static function error(string $message): void
    {
        try {
            Storage::append('logs/error.log', date('c') . ' ' . $message);
        } catch (\Throwable) {
            error_log('EÜDOK: ' . $message);
        }
    }

    /** Änderungsprotokoll der Einstellungen: wer, wann, was. */
    public static function audit(string $area, string $what): void
    {
        $user = Auth::user();
        $line = json_encode([
            'at' => now_iso(),
            'by' => $user['username'] ?? 'setup',
            'area' => $area,
            'change' => $what,
            'ip' => client_ip(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        Storage::append('logs/settings-audit.log', (string) $line);
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
