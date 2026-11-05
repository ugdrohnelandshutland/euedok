<?php
declare(strict_types=1);

namespace Euedok;

use Mpdf\Mpdf;

/**
 * Einsatzbestätigungen: je Person ein PDF (hochgeladene Vorlage + Feldpositionen, sonst eingebautes Layout) und eine Mail.
 * Ablage neben dem Bericht: data/reports/<Jahr>/Einsatzbestaetigung_<Nr>_<Nachname>_<Vorname>[_2].pdf,
 * Versandprotokoll: data/reports/<Jahr>/Einsatzbestaetigung_<Nr>.json (Liste). Der Bericht selbst bleibt unverändert.
 */
final class Confirmations
{
    public const MAX_UPLOAD = 5 * 1024 * 1024;
    public const PLACEHOLDERS = [
        '{nachname}' => 'Nachname',
        '{vorname}' => 'Vorname',
        '{name}' => '„Nachname, Vorname“',
        '{email}' => 'E-Mail der Person',
        '{datum}' => 'Datum des Berichts (Beginn)',
        '{von}' => 'Uhrzeit von',
        '{bis}' => 'Uhrzeit bis',
        '{von_datum}' => 'Datum von',
        '{bis_datum}' => 'Datum bis',
        '{dauer}' => 'Dauer, z. B. 2:30 h',
        '{berichtsnummer}' => 'Berichtsnummer',
        '{berichtsart}' => 'Einsatz / Übung …',
        '{stichwort}' => 'Thema / Stichwort',
        '{ort}' => 'Ort',
        '{einheit}' => 'Name der Einheit',
        '{einheitsfuehrer}' => 'Einheitsführer (Leitung laut Bericht)',
        '{heute}' => 'heutiges Datum',
        '{benutzer_nachname}' => 'Nachname des angemeldeten Benutzers (Versender)',
        '{benutzer_vorname}' => 'Vorname des angemeldeten Benutzers (Versender)',
        '{benutzer_name}' => '„Nachname, Vorname“ des angemeldeten Benutzers (Versender)',
        '{benutzer_email}' => 'E-Mail des angemeldeten Benutzers (Versender)',
    ];

    /** Einstellungen mit Vorlagenliste; der alte Einzelwert „template“ wird zur ersten Vorlage („Standard“). */
    public static function config(): array
    {
        $c = Settings::get('confirmation');
        if (!empty($c['template']) && !$c['templates']) {
            $t = $c['template'];
            $c['templates'] = [['id' => 'standard', 'label' => 'Standard'] + array_intersect_key($t, array_flip(['file', 'name', 'pages', 'uploaded_at']))];
            $c['default_template'] = 'standard';
        }
        unset($c['template']);
        return $c;
    }

    /** @return list<array> Vorlagen (id, label, file, name, pages, uploaded_at) */
    public static function templates(): array
    {
        return array_values(array_filter(self::config()['templates'], static fn ($t) => is_array($t) && isset($t['id'], $t['file'])));
    }

    /** Vorlage zur id; null/unbekannt → Standard bzw. erste Vorlage; keine Vorlagen → null (eingebautes Layout). */
    public static function template(?string $id = null): ?array
    {
        $list = self::templates();
        foreach ($list as $t) {
            if ($t['id'] === $id) {
                return $t;
            }
        }
        $def = self::config()['default_template'] ?? null;
        foreach ($list as $t) {
            if ($t['id'] === $def) {
                return $t;
            }
        }
        return $list[0] ?? null;
    }

    /**
     * Gewählte Vorlage aus dem Formular prüfen: 0 Vorlagen → null, 1 → diese, sonst muss die id existieren.
     * @return array{0: ?string, 1: ?string} [id, Fehler]
     */
    public static function chooseTemplate(mixed $posted): array
    {
        $list = self::templates();
        if (count($list) < 2) {
            return [$list[0]['id'] ?? null, null];
        }
        foreach ($list as $t) {
            if ($t['id'] === $posted) {
                return [$t['id'], null];
            }
        }
        return [self::template()['id'], 'Ungültige Vorlage gewählt.'];
    }

    /** Angemeldeter Zugang → Versenderwerte (zugeordnete Person im Personal, sonst nur Anzeigename). */
    public static function sender(array $u): array
    {
        $p = !empty($u['person_id']) ? MasterData::findPerson($u['person_id']) : null;
        if ($p) {
            return ['lastname' => (string) $p['lastname'], 'firstname' => (string) $p['firstname'], 'name' => (string) $p['name'], 'email' => (string) ($p['email'] ?? '')];
        }
        return ['lastname' => '', 'firstname' => '', 'name' => (string) ($u['display_name'] ?? $u['username'] ?? ''), 'email' => ''];
    }

    /** „Einsatzbestätigung“, „Übungsbestätigung“ (passend zu „Einsatzbericht“, „Übungsbericht“). */
    public static function title(array $type): string
    {
        return (string) preg_replace('/bericht$/u', 'bestätigung', Reports::documentTitle($type));
    }

    private static function dir(array $meta): string
    {
        return 'reports/' . dirname((string) $meta['file']);
    }

    private static function base(array $r): string
    {
        return ascii_name(self::title($r['type'])) . '_' . ascii_name($r['report_number']);
    }

    private static function logPath(array $r, array $meta): string
    {
        return self::dir($meta) . '/' . self::base($r) . '.json';
    }

    /** @return list<array> Versandprotokoll des Berichts (älteste zuerst) */
    public static function entries(array $r, array $meta): array
    {
        $log = Storage::read(self::logPath($r, $meta), []);
        return is_array($log) ? array_values(array_filter($log, 'is_array')) : [];
    }

    /** Vollständiger Pfad – nur für Dateien, die im Protokoll dieses Berichts stehen; sonst null. */
    public static function filePath(array $r, array $meta, string $file): ?string
    {
        if ($file !== basename($file) || str_contains($file, '..') || !preg_match('/^[A-Za-z0-9._-]+\.pdf$/', $file)) {
            return null;
        }
        foreach (self::entries($r, $meta) as $e) {
            if (($e['file'] ?? null) === $file) {
                $path = Storage::path(self::dir($meta) . '/' . $file);
                return is_file($path) ? $path : null;
            }
        }
        return null;
    }

    /** Personen des Berichts: E-Mail aus den Stammdaten, Zeiten abweichend oder Beginn/Ende des Berichts. */
    public static function people(array $r): array
    {
        $out = [];
        foreach ($r['personnel'] as $p) {
            $n = MasterData::normalizePerson(['name' => $p['name']]);
            $out[$p['id']] = [
                'id' => $p['id'],
                'name' => $p['name'],
                'lastname' => $n['lastname'],
                'firstname' => $n['firstname'],
                'email' => (string) (MasterData::findPerson($p['id'])['email'] ?? ''),
                'from' => (string) ($p['from'] ?? $r['start']),
                'to' => (string) ($p['to'] ?? $r['end']),
            ];
        }
        return $out;
    }

    /** null = Versand möglich, sonst deutscher Hinweis. */
    public static function notReady(?string $templateId = null): ?string
    {
        $c = Settings::get('confirmation');
        if (trim((string) $c['subject']) === '' || trim((string) $c['body']) === '') {
            return 'Die Einsatzbestätigung ist noch nicht eingerichtet: Betreff und Mailtext fehlen (Einstellungen → Einsatzbestätigung).';
        }
        if (empty(Settings::mail()['enabled'])) {
            return 'Der Mailversand ist ausgeschaltet (Einstellungen → Mailversand) – es wird nichts versendet.';
        }
        if (($t = self::template($templateId)) && !is_file(Storage::path($t['file']))) {
            return 'Die Vorlage fehlt auf dem Server. Bitte unter Einstellungen → Einsatzbestätigung neu hochladen oder entfernen.';
        }
        return null;
    }

    private static function cleanTime(string $v): ?string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/', $v)) {
            return null;
        }
        $v = substr($v, 0, 16);
        $d = \DateTime::createFromFormat('!Y-m-d\TH:i', $v);
        return $d && $d->format('Y-m-d\TH:i') === $v ? $v : null;
    }

    /**
     * Formulardaten prüfen (nur Personen des Berichts).
     * @return array{0: array, 1: list<string>, 2: array} [gültige ausgewählte Zeilen, Fehler, eingegebene Werte]
     */
    public static function validate(array $people, array $post): array
    {
        $rows = [];
        $errors = [];
        $values = [];
        foreach ($people as $id => $p) {
            $in = is_array($post[$id] ?? null) ? $post[$id] : [];
            $v = [
                'sel' => !empty($in['sel']),
                'email' => mb_substr(trim(is_string($in['email'] ?? null) ? $in['email'] : ''), 0, 120),
                'from' => is_string($in['from'] ?? null) ? $in['from'] : '',
                'to' => is_string($in['to'] ?? null) ? $in['to'] : '',
            ];
            $values[$id] = $v;
            if (!$v['sel']) {
                continue;
            }
            $from = self::cleanTime($v['from']);
            $to = self::cleanTime($v['to']);
            if (!filter_var($v['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Ungültige oder fehlende E-Mail-Adresse bei ' . $p['name'] . '.';
            } elseif ($from === null || $to === null) {
                $errors[] = '„Von“ und „Bis“ bei ' . $p['name'] . ' vollständig angeben.';
            } elseif ($from >= $to) {
                $errors[] = '„Von“ muss bei ' . $p['name'] . ' vor „Bis“ liegen.';
            } else {
                $rows[$id] = ['email' => $v['email'], 'from' => $from, 'to' => $to];
            }
        }
        return [$rows, $errors, $values];
    }

    /** Platzhalter → Wert für eine Person (Zeiten: die für die Bestätigung eingegebenen). */
    public static function vars(array $r, array $person, array $sender = []): array
    {
        $lead = '';
        foreach ($r['personnel'] as $p) {
            if ($p['id'] === ($r['lead'] ?? null)) {
                $lead = $p['name'];
            }
        }
        $min = (int) round((strtotime($person['to']) - strtotime($person['from'])) / 60);
        return [
            '{nachname}' => $person['lastname'],
            '{vorname}' => $person['firstname'],
            '{name}' => $person['name'],
            '{email}' => $person['email'],
            '{datum}' => fmt_date($r['start']),
            '{von}' => substr($person['from'], 11, 5),
            '{bis}' => substr($person['to'], 11, 5),
            '{von_datum}' => fmt_date($person['from']),
            '{bis_datum}' => fmt_date($person['to']),
            '{dauer}' => fmt_duration(max(0, $min)),
            '{berichtsnummer}' => $r['report_number'],
            '{berichtsart}' => $r['type']['label'],
            '{stichwort}' => (string) $r['subject'],
            '{ort}' => (string) $r['location'],
            '{einheit}' => (string) Settings::unit()['name'],
            '{einheitsfuehrer}' => $lead,
            '{heute}' => date('d.m.Y'),
            '{benutzer_nachname}' => (string) ($sender['lastname'] ?? ''),
            '{benutzer_vorname}' => (string) ($sender['firstname'] ?? ''),
            '{benutzer_name}' => (string) ($sender['name'] ?? ''),
            '{benutzer_email}' => (string) ($sender['email'] ?? ''),
            '{leiter}' => $lead, // alter Name, nur noch als Alias (nicht in PLACEHOLDERS)
        ];
    }

    /**
     * Je Person: PDF erzeugen und ablegen, Mail an die Person (CC laut Einstellungen), Protokolleintrag.
     * @param array $rows Personen-ID => ['email', 'from', 'to'] (aus validate)
     * @param array $sender Versenderwerte (sender()), werden im Protokoll gespeichert
     * @return list<array> Protokolleinträge dieses Versands
     */
    public static function send(string $number, array $rows, array $actor, array $sender = [], ?string $templateId = null): array
    {
        $r = Reports::load($number);
        $meta = Reports::meta($number);
        if (!$r || !$meta || $r['status'] !== 'closed') {
            return [];
        }
        $c = self::config();
        $tpl = self::template($templateId);
        $people = self::people($r);
        $results = [];
        foreach ($rows as $id => $row) {
            if (!isset($people[$id])) {
                continue;
            }
            $person = $row + $people[$id];
            $vars = self::vars($r, $person, $sender);
            $entry = [
                'person_id' => $id,
                'name' => $person['name'],
                'email' => $person['email'],
                'cc' => $c['cc'],
                'template' => $tpl['id'] ?? null,
                'template_label' => $tpl['label'] ?? null,
                'sender' => $sender,
                'from' => $person['from'],
                'to' => $person['to'],
                'file' => null,
                'sent_at' => now_iso(),
                'by' => $actor['by'],
                'by_name' => $actor['by_name'] ?? $actor['by'],
                'success' => false,
                'error' => null,
            ];
            try {
                $entry['file'] = self::store($r, $meta, $person, self::render($vars, self::title($r['type']), false, $tpl['id'] ?? null));
                $res = Mailer::sendText([$person['email']], strtr((string) $c['subject'], $vars), strtr((string) $c['body'], $vars), $c['cc'], [
                    Storage::path(self::dir($meta) . '/' . $entry['file']) => $entry['file'],
                ]);
                $entry['success'] = $res['success'];
                $entry['error'] = $res['error'] ?? null;
            } catch (\Throwable $e) {
                Log::error('pdf', 'confirmation_failed', $e->getMessage(), ['report' => $number, 'person' => $id, 'file' => $e->getFile() . ':' . $e->getLine()]);
                $entry['error'] = 'PDF konnte nicht erzeugt werden.';
            }
            Storage::locked('confirm-' . $number, static function () use ($r, $meta, $entry) {
                $log = self::entries($r, $meta);
                $log[] = $entry;
                Storage::write(self::logPath($r, $meta), $log);
            });
            $results[] = $entry;
        }
        Log::info('report', 'confirmations_sent', $number . ': ' . count($results) . ' Bestätigung(en)', [
            'ok' => count(array_filter($results, static fn ($e) => $e['success'])),
            'by' => $actor['by'],
        ]);
        return $results;
    }

    /** Freien Namen suchen (…, …_2, …_3) und schreiben; alte Fassungen bleiben. @return string Dateiname */
    private static function store(array $r, array $meta, array $person, string $pdf): string
    {
        $base = self::base($r) . '_' . (ascii_name($person['lastname']) ?: 'Person') . (ascii_name($person['firstname']) !== '' ? '_' . ascii_name($person['firstname']) : '');
        return Storage::locked('confirm-' . $r['report_number'], static function () use ($meta, $base, $pdf) {
            $n = 1;
            do {
                $file = $base . ($n > 1 ? '_' . $n : '') . '.pdf';
                $n++;
            } while (is_file(Storage::path(self::dir($meta) . '/' . $file)));
            Storage::writeRaw(self::dir($meta) . '/' . $file, $pdf);
            return $file;
        });
    }

    /* ---------- PDF ---------- */

    /** Seitenzahl der Vorlage; wirft, wenn FPDI (freier Parser) sie nicht lesen kann (z. B. PDF ≥ 1.5 mit komprimierter xref). */
    public static function templatePages(string $file): int
    {
        return Pdf::make()->setSourceFile($file);
    }

    /** Vorschau mit Beispieldaten; $grid = 10-mm-Raster mit Beschriftung. */
    public static function preview(bool $grid, ?string $templateId = null, array $sender = []): string
    {
        $day = date('Y-m-d');
        $next = date('Y-m-d', strtotime('+1 day'));
        $r = [
            'report_number' => 'E-' . date('Y') . '-001',
            'type' => ['id' => 'incident', 'label' => 'Einsatz'],
            'subject' => 'Vermisste Person',
            'location' => 'Musterstadt, Isarauen',
            'start' => $day . 'T22:00',
            'personnel' => [['id' => 'lead', 'name' => 'Leiter, Lena']],
            'lead' => 'lead',
        ];
        $person = ['name' => 'Mustermann, Max', 'lastname' => 'Mustermann', 'firstname' => 'Max', 'email' => 'max.mustermann@example.org', 'from' => $day . 'T22:00', 'to' => $next . 'T01:30'];
        return self::render(self::vars($r, $person, $sender), self::title($r['type']), $grid, $templateId);
    }

    /** PDF als Text: mit Vorlage (FPDI) jede Seite importieren und die Felder setzen, sonst eingebautes Layout. */
    public static function render(array $vars, string $title, bool $grid = false, ?string $templateId = null): string
    {
        $c = self::config();
        $t = self::template($templateId);
        $tpl = $t ? Storage::path($t['file']) : null;
        if ($tpl === null || !is_file($tpl)) {
            $m = Pdf::make();
            $m->SetTitle($title);
            $m->WriteHTML(self::fallbackHtml($vars, $title));
            return $m->Output('', \Mpdf\Output\Destination::STRING_RETURN);
        }
        $m = Pdf::make(['margin_left' => 0, 'margin_right' => 0, 'margin_top' => 0, 'margin_bottom' => 0, 'margin_header' => 0, 'margin_footer' => 0]);
        $m->SetTitle($title);
        $m->SetAutoPageBreak(false);
        $m->cMarginL = $m->cMarginR = $m->cMarginT = $m->cMarginB = 0; // x/y genau wie eingetragen
        $pages = $m->setSourceFile($tpl);
        for ($i = 1; $i <= $pages; $i++) {
            $id = $m->importPage($i);
            $size = $m->getTemplateSize($id);
            $landscape = $size['width'] > $size['height'];
            $m->AddPageByArray(['orientation' => $landscape ? 'L' : 'P', 'sheet-size' => [min($size['width'], $size['height']), max($size['width'], $size['height'])]]);
            $m->useTemplate($id);
            if ($grid) {
                self::grid($m, (float) $size['width'], (float) $size['height']);
            }
            foreach ($c['fields'] as $f) {
                if ((int) $f['page'] === $i) {
                    self::field($m, $f, strtr((string) $f['text'], $vars));
                }
            }
        }
        return $m->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }

    private static function field(Mpdf $m, array $f, string $text): void
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($text === '') {
            return;
        }
        $size = (float) $f['size'];
        $w = (float) $f['w'];
        $align = in_array($f['align'] ?? 'L', ['L', 'C', 'R'], true) ? $f['align'] : 'L';
        $m->SetFont('dejavusans', !empty($f['bold']) ? 'B' : '', $size);
        $m->SetTextColor(0);
        if ((int) $f['lines'] <= 1) {
            // einzeilig: bis 6 pt verkleinern, dann mit „…“ kürzen
            while ($w > 0 && $size > 6 && $m->GetStringWidth($text) > $w) {
                $size -= 0.5;
                $m->SetFontSize($size);
            }
            $lines = [$w > 0 ? self::cut($m, $text, $w) : $text];
        } else {
            $lines = self::wrap($m, $text, $w > 0 ? $w : 1000.0, (int) $f['lines']);
        }
        $lh = $size * 0.3528 * 1.25; // Zeilenhöhe in mm
        foreach ($lines as $n => $line) {
            $lw = $w > 0 ? $w : $m->GetStringWidth($line) + 0.2;
            // Breite 0: x ist Anfang (L), Mitte (C) oder Ende (R) des Textes
            $x = (float) $f['x'] - ($w > 0 ? 0 : match ($align) { 'C' => $lw / 2, 'R' => $lw, default => 0 });
            $m->SetXY($x, (float) $f['y'] + $n * $lh);
            $m->Cell($lw, $lh, $line, 0, 0, $align);
        }
    }

    /** Wortweise umbrechen; mehr als $max Zeilen → letzte Zeile mit „…“ gekürzt. */
    private static function wrap(Mpdf $m, string $text, float $w, int $max): array
    {
        $lines = [];
        $cur = '';
        foreach (explode(' ', $text) as $word) {
            $try = $cur === '' ? $word : $cur . ' ' . $word;
            if ($cur !== '' && $m->GetStringWidth($try) > $w) {
                $lines[] = $cur;
                $cur = $word;
            } else {
                $cur = $try;
            }
        }
        $lines[] = $cur;
        if (count($lines) > $max) {
            $lines = array_merge(array_slice($lines, 0, $max - 1), [implode(' ', array_slice($lines, $max - 1))]);
        }
        return array_map(static fn ($l) => self::cut($m, $l, $w), $lines);
    }

    private static function cut(Mpdf $m, string $t, float $w): string
    {
        if ($m->GetStringWidth($t) <= $w) {
            return $t;
        }
        while (mb_strlen($t) > 1 && $m->GetStringWidth($t . '…') > $w) {
            $t = mb_substr($t, 0, -1);
        }
        return rtrim($t) . '…';
    }

    private static function grid(Mpdf $m, float $w, float $h): void
    {
        $m->SetDrawColor(150, 190, 230);
        $m->SetLineWidth(0.1);
        $m->SetFont('dejavusans', '', 5);
        $m->SetTextColor(60, 110, 170);
        for ($x = 10; $x < $w; $x += 10) {
            $m->Line($x, 0, $x, $h);
            $m->SetXY($x + 0.5, 0.8);
            $m->Cell(8, 2, (string) $x, 0, 0, 'L');
        }
        for ($y = 10; $y < $h; $y += 10) {
            $m->Line(0, $y, $w, $y);
            $m->SetXY(0.8, $y + 0.5);
            $m->Cell(8, 2, (string) $y, 0, 0, 'L');
        }
        $m->SetTextColor(0);
        $m->SetDrawColor(0);
    }

    /** Eingebautes Layout ohne Vorlage. */
    private static function fallbackHtml(array $v, string $title): string
    {
        $h = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $logo = '';
        foreach (['png', 'jpg', 'jpeg'] as $ext) {
            if (is_file($f = Storage::path('branding/logo.' . $ext))) {
                $logo = '<img src="' . $h($f) . '" style="height:16mm">';
                break;
            }
        }
        $row = static fn (string $l, string $val) => '<tr><td style="width:42mm;color:#3b5063;padding:4pt 0">' . $h($l) . '</td><td style="font-weight:bold;padding:4pt 0">' . $h($val !== '' ? $val : '–') . '</td></tr>';
        $period = $v['{von_datum}'] . ' ' . $v['{von}'] . ' Uhr – ' . ($v['{bis_datum}'] !== $v['{von_datum}'] ? $v['{bis_datum}'] . ' ' : '') . $v['{bis}'] . ' Uhr';
        return '<div style="font-family:dejavusans;font-size:11pt;color:#000;line-height:1.4">'
            . '<table width="100%" style="border-bottom:1.5pt solid #000"><tr><td style="padding-bottom:4pt"><b style="font-size:14pt">' . $h($v['{einheit}'] ?: 'EÜDOK') . '</b></td>'
            . '<td style="text-align:right;padding-bottom:4pt">' . $logo . '</td></tr></table>'
            . '<div style="font-size:24pt;font-weight:bold;margin-top:16mm">' . $h($title) . '</div>'
            . '<p style="margin-top:10mm">Hiermit wird die Teilnahme bestätigt von</p>'
            . '<p style="font-size:16pt;font-weight:bold;margin:3mm 0 8mm">' . $h(trim($v['{vorname}'] . ' ' . $v['{nachname}'])) . '</p>'
            . '<table style="border-collapse:collapse">'
            . $row('Art', $v['{berichtsart}']) . $row('Berichts-Nr.', $v['{berichtsnummer}']) . $row('Datum', $v['{datum}'])
            . $row('Zeitraum', $period) . $row('Dauer', $v['{dauer}']) . $row('Stichwort', $v['{stichwort}']) . $row('Ort', $v['{ort}'])
            . '</table>'
            . '<table width="100%" style="margin-top:40mm;border-collapse:collapse"><tr>'
            . '<td width="45%" style="border-top:0.6pt solid #000;padding-top:3pt;font-size:9pt">Ort, Datum</td><td width="10%"></td>'
            . '<td width="45%" style="border-top:0.6pt solid #000;padding-top:3pt;font-size:9pt">Unterschrift' . ($v['{einheitsfuehrer}'] !== '' ? ' (' . $h($v['{einheitsfuehrer}']) . ')' : '') . '</td>'
            . '</tr></table></div>';
    }
}
