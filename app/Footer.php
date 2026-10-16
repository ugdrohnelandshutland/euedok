<?php
declare(strict_types=1);

namespace Euedok;

/**
 * PDF-Fußzeile: drei frei gestaltbare Textfelder (links, Mitte, rechts).
 * Formatierung: **fett**, *kursiv*, __unterstrichen__, Zeilenumbrüche.
 * Platzhalter: {nummer} {seite} {seiten} {datum} {einheit}
 */
final class Footer
{
    public const POSITIONS = ['left' => 'Links', 'center' => 'Mitte', 'right' => 'Rechts'];
    public const DEFAULT_RIGHT = '{nummer} · Seite {seite} / {seiten}';
    public const PLACEHOLDERS = [
        '{nummer}' => 'Berichtsnummer',
        '{seite}' => 'aktuelle Seite',
        '{seiten}' => 'Seitenzahl gesamt',
        '{datum}' => 'Datum des Berichts',
        '{einheit}' => 'Name der Einheit',
    ];
    private const MAX = 1000;

    /** @return array{left: string, center: string, right: string} – übernimmt alte Felder (Leitung/Adresse/Kontakt) */
    public static function parts(?array $unit = null): array
    {
        $f = ($unit ?? Settings::unit())['footer'] ?? [];
        $parts = [
            'left' => (string) ($f['left'] ?? ''),
            'center' => (string) ($f['center'] ?? ''),
            'right' => (string) ($f['right'] ?? self::DEFAULT_RIGHT),
        ];
        $old = array_filter([trim((string) ($f['lead'] ?? '')), trim((string) ($f['address'] ?? '')), trim((string) ($f['contact'] ?? ''))]);
        if ($old && !isset($f['left']) && !isset($f['center'])) {
            $parts['left'] = trim((string) ($f['lead'] ?? ''));
            $parts['center'] = trim((string) ($f['address'] ?? ''));
            $parts['right'] = trim(trim((string) ($f['contact'] ?? '')) . "\n" . self::DEFAULT_RIGHT);
        }
        return $parts;
    }

    public static function clean(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        return mb_substr(trim($text), 0, self::MAX);
    }

    /** Text mit Auszeichnungen → sicheres HTML für mPDF. */
    public static function toHtml(string $text, array $vars): string
    {
        $h = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $h = (string) preg_replace('/\*\*(.+?)\*\*/su', '<b>$1</b>', $h);
        $h = (string) preg_replace('/__(.+?)__/su', '<u>$1</u>', $h);
        $h = (string) preg_replace('/(?<![\*\w])\*(?!\s)(.+?)(?<!\s)\*(?![\*\w])/su', '<i>$1</i>', $h);
        $h = strtr($h, [
            '{seite}' => '{PAGENO}',
            '{seiten}' => '{nbpg}',
            '{nummer}' => htmlspecialchars((string) ($vars['number'] ?? ''), ENT_QUOTES, 'UTF-8'),
            '{datum}' => htmlspecialchars((string) ($vars['date'] ?? ''), ENT_QUOTES, 'UTF-8'),
            '{einheit}' => htmlspecialchars((string) ($vars['unit'] ?? ''), ENT_QUOTES, 'UTF-8'),
        ]);
        return nl2br($h, false);
    }

    public static function lineCount(array $parts): int
    {
        $max = 1;
        foreach ($parts as $p) {
            $max = max($max, substr_count(trim($p), "\n") + 1);
        }
        return $max;
    }
}
